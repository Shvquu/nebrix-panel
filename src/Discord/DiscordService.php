<?php

declare(strict_types=1);

namespace Nebrix\Discord;

use Nebrix\Audit\AuditLogger;
use Nebrix\Auth\User;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Integration\Discord\DiscordClient;
use Nebrix\Integration\IntegrationException;
use Nebrix\Queue\JobQueue;
use Nebrix\Session\Session;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Links customer accounts with Discord accounts (OAuth2) and keeps the
 * customer roles on the community server in sync.
 *
 * Linking: the OAuth2 "state" is random, bound to the session, single-use
 * and expires after 10 minutes, so nobody can link a foreign Discord
 * account to a customer (or the other way round). The user's access token
 * is used once – to read the Discord ID and, if enabled, to add the user to
 * the server – and never stored. A Discord account can be linked to one
 * customer account only.
 *
 * Roles: "linked" for every linked customer, "active" while the customer
 * has an active service. The bot only removes roles the panel granted
 * itself, so roles staff gave by hand stay untouched. Changes run in the
 * job queue (Discord rate limits are retried), the scheduler re-checks all
 * links periodically.
 */
class DiscordService
{
    public const JOB_SYNC = 'discord.sync';
    private const STATE_KEY = '_discord_oauth_state';
    private const STATE_TTL = 600;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly DiscordClient $client,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly JobQueue $jobs,
        private readonly AuditLogger $audit,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Linking is offered when it is enabled and the OAuth2 application is configured. */
    public function isEnabled(): bool
    {
        return $this->settings->bool('discord.enabled')
            && $this->settings->string('discord.client_id') !== ''
            && $this->settings->string('discord.client_secret') !== '';
    }

    /** Server and bot are configured: members can be added and roles synced. */
    public function canManageServer(): bool
    {
        return $this->settings->string('discord.bot_token') !== '' && $this->settings->string('discord.guild_id') !== '';
    }

    public function redirectUri(): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/account/discord/callback';
    }

    public function inviteUrl(): string
    {
        return $this->settings->string('discord.invite_url');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function link(int $userId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_discord_links` WHERE `user_id` = ?', [$userId]);
    }

    /** Starts the OAuth2 flow: returns the Discord authorization URL. */
    public function startLink(Session $session): string
    {
        if (!$this->isEnabled()) {
            throw new DiscordLinkException('Discord linking is not available.');
        }
        $state = bin2hex(random_bytes(32));
        $session->put(self::STATE_KEY, ['hash' => hash('sha256', $state), 'expires' => $this->clock->now()->getTimestamp() + self::STATE_TTL]);

        $scopes = ['identify'];
        if ($this->settings->bool('discord.join_guild') && $this->canManageServer()) {
            $scopes[] = 'guilds.join';
        }

        return DiscordClient::authorizeUrl($this->settings->string('discord.client_id'), $this->redirectUri(), $state, $scopes);
    }

    /**
     * Completes the OAuth2 flow for the signed-in customer.
     *
     * @return array<string, mixed> the stored link
     *
     * @throws DiscordLinkException with a message for the customer
     */
    public function completeLink(User $user, Session $session, string $code, string $state, ?string $ip): array
    {
        $expected = $session->pull(self::STATE_KEY);
        if (!is_array($expected) || !is_string($expected['hash'] ?? null) || (int) ($expected['expires'] ?? 0) < $this->clock->now()->getTimestamp()
            || $state === '' || !hash_equals($expected['hash'], hash('sha256', $state))) {
            throw new DiscordLinkException('The Discord link has expired or is invalid. Please try again.');
        }
        if (!$this->isEnabled() || preg_match('/^[A-Za-z0-9._~-]{1,200}$/', $code) !== 1) {
            throw new DiscordLinkException('Discord linking is not available.');
        }

        try {
            $token = $this->client->exchangeCode($this->settings->string('discord.client_id'), $this->settings->string('discord.client_secret'), $code, $this->redirectUri());
            $account = $this->client->currentUser($token);
        } catch (IntegrationException $exception) {
            $this->logger->warning('Discord link failed', ['user' => $user->id, 'message' => $exception->getMessage()]);

            throw new DiscordLinkException('Discord could not be reached. Please try again later.');
        }

        $taken = $this->db->fetchValue('SELECT `user_id` FROM `nx_discord_links` WHERE `discord_id` = ?', [$account['id']]);
        if ($taken !== null && (int) $taken !== $user->id) {
            throw new DiscordLinkException('This Discord account is already linked to another customer account.');
        }

        $previous = $this->link($user->id);
        $now = $this->clock->nowString();
        $this->db->transaction(function () use ($user, $account, $now, $previous): void {
            if ($previous !== null && $previous['discord_id'] !== $account['id']) {
                // Another Discord account was linked before: its roles are removed.
                $this->queueRemoval((string) $previous['discord_id'], self::roles($previous['synced_roles']));
                $this->db->run('DELETE FROM `nx_discord_links` WHERE `user_id` = ?', [$user->id]);
                $previous = null;
            }
            if ($previous === null) {
                $this->db->insert('nx_discord_links', ['user_id' => $user->id, 'discord_id' => $account['id'], 'username' => $account['username'], 'global_name' => $account['global_name'], 'linked_at' => $now]);
            } else {
                $this->db->update('nx_discord_links', ['username' => $account['username'], 'global_name' => $account['global_name']], ['user_id' => $user->id]);
            }
        });

        // Join the server with the user's token (used only now).
        if ($this->settings->bool('discord.join_guild') && $this->canManageServer()) {
            try {
                $this->client->addMember($this->settings->string('discord.bot_token'), $this->settings->string('discord.guild_id'), $account['id'], $token, []);
                $this->db->update('nx_discord_links', ['in_guild' => 1], ['user_id' => $user->id]);
            } catch (IntegrationException $exception) {
                $this->logger->warning('Adding the Discord member failed', ['user' => $user->id, 'message' => $exception->getMessage()]);
                $this->db->update('nx_discord_links', ['sync_error' => mb_substr($exception->getMessage(), 0, 255)], ['user_id' => $user->id]);
            }
        }

        $this->audit->log($user->id, 'discord.linked', 'user', $user->id, ['discord_id' => $account['id']], $ip);
        $this->queueSync($user->id);

        return $this->link($user->id) ?? throw new \RuntimeException('Discord link not stored.');
    }

    /** Removes the link; roles the panel granted are removed in the background. */
    public function unlink(int $userId, ?int $actorId, ?string $ip): bool
    {
        $link = $this->link($userId);
        if ($link === null) {
            return false;
        }
        $this->db->run('DELETE FROM `nx_discord_links` WHERE `user_id` = ?', [$userId]);
        $this->queueRemoval((string) $link['discord_id'], self::roles($link['synced_roles']));
        $this->audit->log($actorId, 'discord.unlinked', 'user', $userId, ['discord_id' => $link['discord_id']], $ip);

        return true;
    }

    /**
     * Roles the customer should have on the server.
     *
     * @return list<string>
     */
    public function desiredRoles(int $userId): array
    {
        $roles = [];
        $active = $this->db->fetchValue("SELECT `status` FROM `nx_users` WHERE `id` = ?", [$userId]) === User::STATUS_ACTIVE;
        if (!$active) {
            return [];
        }
        $linked = $this->settings->string('discord.role_linked');
        if ($linked !== '') {
            $roles[] = $linked;
        }
        $customer = $this->settings->string('discord.role_active');
        if ($customer !== '' && (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_services` WHERE `user_id` = ? AND `status` = 'active'", [$userId]) > 0) {
            $roles[] = $customer;
        }

        return array_values(array_unique($roles));
    }

    /**
     * Applies the desired roles to one linked customer (job).
     *
     * @throws IntegrationException when Discord fails (the job is retried)
     */
    public function sync(int $userId): void
    {
        $link = $this->link($userId);
        if ($link === null || !$this->canManageServer()) {
            return;
        }
        $bot = $this->settings->string('discord.bot_token');
        $guild = $this->settings->string('discord.guild_id');
        $discordId = (string) $link['discord_id'];
        $managed = array_values(array_filter([$this->settings->string('discord.role_linked'), $this->settings->string('discord.role_active')]));
        $desired = $this->desiredRoles($userId);
        $granted = self::roles($link['synced_roles']);

        $current = $this->client->memberRoles($bot, $guild, $discordId);
        if ($current === null) {
            $this->db->update('nx_discord_links', ['in_guild' => 0, 'last_synced_at' => $this->clock->nowString(), 'sync_error' => 'not on the server'], ['user_id' => $userId]);

            return;
        }

        foreach ($managed as $role) {
            $wanted = in_array($role, $desired, true);
            $has = in_array($role, $current, true);
            if ($wanted && !$has) {
                $this->client->addRole($bot, $guild, $discordId, $role, 'Customer account linked in the panel');
                $granted[] = $role;
            } elseif (!$wanted && $has && in_array($role, $granted, true)) {
                // Only roles the panel granted are taken away again.
                $this->client->removeRole($bot, $guild, $discordId, $role, 'No longer applies to the customer account');
            }
            if (!$wanted) {
                $granted = array_values(array_diff($granted, [$role]));
            }
        }

        $this->db->update('nx_discord_links', [
            'synced_roles' => implode(',', array_values(array_unique(array_intersect($granted, $managed)))) ?: null,
            'in_guild' => 1,
            'last_synced_at' => $this->clock->nowString(),
            'sync_error' => null,
        ], ['user_id' => $userId]);
    }

    /**
     * Removes roles from a Discord account that is no longer linked (job).
     *
     * @param list<string> $roles
     */
    public function removeRoles(string $discordId, array $roles): void
    {
        if (!$this->canManageServer()) {
            return;
        }
        foreach ($roles as $role) {
            $this->client->removeRole($this->settings->string('discord.bot_token'), $this->settings->string('discord.guild_id'), $discordId, $role, 'Customer account unlinked in the panel');
        }
    }

    /**
     * Scheduler: queues a sync for links whose roles may have changed (or
     * that were not checked for a day).
     */
    public function syncAll(): int
    {
        if (!$this->canManageServer()) {
            return 0;
        }
        $queued = 0;
        $stale = $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s');
        foreach ($this->db->fetchAll('SELECT `user_id`, `synced_roles`, `last_synced_at`, `in_guild` FROM `nx_discord_links` ORDER BY `last_synced_at` LIMIT 500') as $link) {
            $desired = $this->desiredRoles((int) $link['user_id']);
            $granted = self::roles($link['synced_roles']);
            sort($desired);
            sort($granted);
            if ($desired !== $granted || $link['last_synced_at'] === null || $link['last_synced_at'] < $stale) {
                $this->queueSync((int) $link['user_id']);
                $queued++;
            }
        }

        return $queued;
    }

    public function queueSync(int $userId): void
    {
        if ($this->canManageServer()) {
            $this->jobs->dispatch(self::JOB_SYNC, ['user_id' => $userId], self::JOB_SYNC . ':' . $userId . ':' . $this->clock->now()->format('YmdHi'));
        }
    }

    /**
     * @param list<string> $roles
     */
    private function queueRemoval(string $discordId, array $roles): void
    {
        if ($roles !== [] && $this->canManageServer()) {
            $this->jobs->dispatch(self::JOB_SYNC, ['discord_id' => $discordId, 'remove' => $roles]);
        }
    }

    /**
     * @return list<string>
     */
    private static function roles(mixed $stored): array
    {
        return array_values(array_filter(explode(',', (string) $stored), static fn (string $role): bool => preg_match('/^\d{17,20}$/', $role) === 1));
    }
}
