<?php

declare(strict_types=1);

namespace Nebrix\Notification;

use Nebrix\Database\Connection;
use Nebrix\Integration\Discord\DiscordClient;
use Nebrix\Integration\Discord\DiscordRateLimited;
use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Queue\JobHandler;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Sends a notification as Discord direct message: {"user_id": n, "text": "…"}.
 * The Discord account is looked up when the job runs, so an unlinked
 * account gets nothing. A user who does not accept direct messages from
 * the bot (403) is not retried.
 */
final class DiscordDirectMessageJob implements JobHandler
{
    public function __construct(
        private readonly DiscordClient $discord,
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
        private readonly NotificationRouter $router,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $text = trim((string) ($payload['text'] ?? ''));
        $discordId = $this->db->fetchValue('SELECT `discord_id` FROM `nx_discord_links` WHERE `user_id` = ?', [$userId]);
        if (!is_string($discordId) || $text === '' || !$this->router->directMessagesAvailable()) {
            return;
        }

        try {
            $this->discord->sendDirectMessage($this->settings->string('discord.bot_token'), $discordId, $text);
        } catch (DiscordRateLimited $limited) {
            throw new RetryLaterException('Discord rate limit.', max(1, (int) ceil($limited->retryAfter)));
        } catch (IntegrationException $exception) {
            $status = $exception->httpStatus();
            if (in_array($status, [400, 403, 404], true)) {
                $this->logger->info('Discord direct message not delivered', ['user' => $userId, 'status' => $status]);

                throw new PermanentJobFailure('Discord refused the direct message (HTTP ' . $status . ').');
            }

            throw $exception;
        }
    }
}
