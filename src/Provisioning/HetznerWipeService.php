<?php

declare(strict_types=1);

namespace Nebrix\Provisioning;

use Nebrix\Audit\AuditLogger;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Integration\Hetzner\RescueShell;
use Nebrix\Integration\Hetzner\RobotClient;
use Nebrix\Integration\Integration;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use phpseclib3\Crypt\EC;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Automatic disk wiping of returned Hetzner servers (servers named
 * "nebrix-wipe-<number>").
 *
 * pending  → the rescue system is activated with the panel's SSH key and the
 *            server is reset
 * booting  → as soon as SSH answers, the wipe script is started in the
 *            background (it survives the SSH session)
 * running  → the state file is polled; "done" renames the server back into
 *            the pool ("<pool prefix>-<number>")
 * done / failed
 *
 * The server is only touched while it still carries its wipe name, so a
 * server that staff took over by hand is never wiped by surprise.
 */
class HetznerWipeService
{
    public const PENDING = 'pending';
    public const BOOTING = 'booting';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    private const BOOT_WAIT_SECONDS = 120;
    private const BOOT_TIMEOUT_SECONDS = 1200;
    private const RUN_TIMEOUT_SECONDS = 259200; // 72 hours (large HDDs)
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly IntegrationManager $integrations,
        private readonly IntegrationRepository $integrationRepository,
        private readonly RescueShell $shell,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function wipeName(int $serverNumber): string
    {
        return 'nebrix-wipe-' . $serverNumber;
    }

    /** Queues a server (no second open entry for the same server). */
    public function enqueue(Integration $integration, int $serverNumber, ?int $serviceId): ?int
    {
        return $this->db->transaction(function () use ($integration, $serverNumber, $serviceId): ?int {
            $open = $this->db->fetchValue(
                "SELECT `id` FROM `nx_hetzner_wipes` WHERE `integration_id` = ? AND `server_number` = ? AND `status` IN ('pending', 'booting', 'running') FOR UPDATE",
                [$integration->id, $serverNumber],
            );
            if ($open !== null) {
                return null;
            }
            $now = $this->clock->nowString();

            return $this->db->insert('nx_hetzner_wipes', [
                'integration_id' => $integration->id,
                'server_number' => $serverNumber,
                'service_id' => $serviceId,
                'status' => self::PENDING,
                'method' => (string) $integration->config('wipe_method', 'full') === 'quick' ? 'quick' : 'full',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Queues every server of the account that is waiting for a wipe
     * (e.g. returned before automatic wiping was switched on).
     */
    public function enqueueWaiting(Integration $integration): int
    {
        $client = $this->integrations->hetznerRobot($integration);
        $queued = 0;
        foreach ($client->servers() as $server) {
            $number = (int) ($server['server_number'] ?? 0);
            if ($number > 0 && (string) ($server['server_name'] ?? '') === self::wipeName($number) && $this->enqueue($integration, $number, null) !== null) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Scheduler: advances every open wipe by one step.
     *
     * @return array{started: int, running: int, done: int, failed: int}
     */
    public function process(): array
    {
        $result = ['started' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        $rows = $this->db->fetchAll("SELECT * FROM `nx_hetzner_wipes` WHERE `status` IN ('pending', 'booting', 'running') ORDER BY `id` LIMIT 20");
        foreach ($rows as $wipe) {
            try {
                $integration = $this->integrationRepository->find((int) $wipe['integration_id']);
                if ($integration === null || !$integration->enabled || !(bool) $integration->config('auto_wipe', false)) {
                    continue; // switched off: the entry waits
                }
                $outcome = $this->step($wipe, $integration);
            } catch (Throwable $exception) {
                // Robot or SSH errors: logged, the step is retried on the next run.
                $this->logger->warning('Hetzner disk wipe step failed', ['wipe' => $wipe['id'], 'server' => $wipe['server_number'], 'message' => $exception->getMessage()]);
                $this->db->update('nx_hetzner_wipes', ['error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => $this->clock->nowString()], ['id' => $wipe['id']]);
                continue;
            }
            if (isset($result[$outcome])) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /** Staff restart a failed wipe. */
    public function retry(int $id, int $actorId, ?string $ip): bool
    {
        $changed = $this->db->run(
            "UPDATE `nx_hetzner_wipes` SET `status` = 'pending', `attempts` = 0, `error` = NULL, `updated_at` = ? WHERE `id` = ? AND `status` = 'failed'",
            [$this->clock->nowString(), $id],
        )->rowCount() > 0;
        if ($changed) {
            $this->audit->log($actorId, 'hetzner.wipe_retried', 'hetzner_wipe', $id, [], $ip);
        }

        return $changed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forIntegration(int $integrationId): array
    {
        return $this->db->fetchAll('SELECT * FROM `nx_hetzner_wipes` WHERE `integration_id` = ? ORDER BY `id` DESC LIMIT 100', [$integrationId]);
    }

    /**
     * @param array<string, mixed> $wipe
     * @return string the outcome for the statistics
     */
    private function step(array $wipe, Integration $integration): string
    {
        $client = $this->integrations->hetznerRobot($integration);
        $number = (int) $wipe['server_number'];
        $server = $client->server($number);
        if ((string) ($server['server_name'] ?? '') !== self::wipeName($number)) {
            return $this->fail($wipe, sprintf('The server is no longer named "%s" (taken over by hand); it was not wiped.', self::wipeName($number)));
        }
        $ip = (string) ($server['server_ip'] ?? '');
        $now = $this->clock->now()->getTimestamp();

        if ($wipe['status'] === self::PENDING) {
            if ((int) $wipe['attempts'] >= self::MAX_ATTEMPTS) {
                return $this->fail($wipe, 'The rescue system could not be reached after several resets.');
            }
            [$fingerprint] = $this->key($integration, $client);
            // A firewall left by a suspension would block SSH to the rescue system, and the
            // former customer's rules must not reach the next one.
            if (!$client->clearFirewallIfConfigured($number)) {
                return 'running'; // Robot is applying a firewall change: next run
            }
            // Only one boot configuration can be active; a rescue system without our key is replaced.
            if (($client->rescue($number)['active'] ?? false) === true) {
                $client->deactivateRescue($number);
            }
            $rescue = $client->activateRescue($number, [$fingerprint]);
            $hostKeys = [];
            foreach (is_array($rescue['host_key'] ?? null) ? $rescue['host_key'] : [] as $entry) {
                $data = is_array($entry) ? (string) (($entry['key']['data'] ?? $entry['data'] ?? '')) : '';
                if ($data !== '') {
                    $hostKeys[] = $data;
                }
            }
            $client->reset($number, 'hw');
            $this->update($wipe, [
                'status' => self::BOOTING,
                'attempts' => (int) $wipe['attempts'] + 1,
                'host_keys' => json_encode($hostKeys, JSON_THROW_ON_ERROR),
                'reset_at' => $this->clock->nowString(),
                'started_at' => $wipe['started_at'] ?? $this->clock->nowString(),
                'error' => null,
            ]);
            $this->audit->log(null, 'hetzner.wipe_started', 'hetzner_wipe', (int) $wipe['id'], ['server' => $number]);

            return 'started';
        }

        $since = $now - strtotime((string) $wipe['reset_at'] . ' UTC');
        if ($wipe['status'] === self::BOOTING) {
            if ($since < self::BOOT_WAIT_SECONDS) {
                return 'running';
            }
            try {
                $this->shell->run($ip, $this->privateKey($integration), $this->hostKeys($wipe), self::startCommand((string) $wipe['method']));
            } catch (IntegrationException $exception) {
                if ($since > self::BOOT_TIMEOUT_SECONDS) {
                    // Not reachable: reset again (a new attempt) or give up.
                    $this->update($wipe, ['status' => self::PENDING, 'error' => mb_substr($exception->getMessage(), 0, 500)]);
                }

                return 'running';
            }
            $this->update($wipe, ['status' => self::RUNNING, 'error' => null]);

            return 'running';
        }

        // running
        try {
            $output = $this->shell->run($ip, $this->privateKey($integration), $this->hostKeys($wipe), 'cat /root/nebrix-wipe.state 2>/dev/null; echo "--"; tail -n 30 /root/nebrix-wipe.log 2>/dev/null');
        } catch (IntegrationException $exception) {
            if ($now - strtotime((string) $wipe['started_at'] . ' UTC') > self::RUN_TIMEOUT_SECONDS) {
                return $this->fail($wipe, 'The wipe did not finish within 72 hours: ' . $exception->getMessage());
            }
            $this->update($wipe, ['error' => mb_substr($exception->getMessage(), 0, 500)]);

            return 'running';
        }
        [$state, $log] = array_pad(explode("--\n", $output, 2), 2, '');
        $state = trim($state);
        $log = mb_substr(trim($log), -4000);
        if ($state === 'done') {
            $prefix = (string) $integration->config('pool_prefix', 'nebrix-free');
            $client->renameServer($number, mb_substr($prefix . '-' . $number, 0, 100));
            $this->update($wipe, ['status' => self::DONE, 'finished_at' => $this->clock->nowString(), 'log' => $log, 'error' => null]);
            $this->audit->log(null, 'hetzner.wipe_completed', 'hetzner_wipe', (int) $wipe['id'], ['server' => $number]);

            return 'done';
        }
        if ($state === 'failed') {
            $this->update($wipe, ['log' => $log]);

            return $this->fail($wipe, 'The wipe script reported an error (see the log).');
        }
        if ($state === '') {
            // The rescue system was restarted (state lost): start over.
            $this->update($wipe, ['status' => self::PENDING, 'log' => $log, 'error' => 'The rescue system was restarted; the wipe starts again.']);

            return 'running';
        }
        if ($now - strtotime((string) $wipe['started_at'] . ' UTC') > self::RUN_TIMEOUT_SECONDS) {
            return $this->fail($wipe, 'The wipe did not finish within 72 hours.');
        }
        $this->update($wipe, ['log' => $log]);

        return 'running';
    }

    /**
     * The panel's SSH key: created and stored in Robot on first use.
     *
     * @return array{0: string, 1: string} fingerprint and private key
     */
    private function key(Integration $integration, RobotClient $client): array
    {
        $private = $integration->hasSecret('wipe_ssh_key') ? $integration->secret('wipe_ssh_key') : '';
        if ($private === '') {
            $key = EC::createKey('Ed25519');
            $private = $key->toString('OpenSSH');
            $this->integrationRepository->storeGenerated($integration->id, ['wipe_ssh_key' => $private], []);
        }
        /** @var \phpseclib3\Crypt\Common\PrivateKey $loaded */
        $loaded = \phpseclib3\Crypt\PublicKeyLoader::load($private);
        $public = $loaded->getPublicKey();
        $fingerprint = (string) $public->getFingerprint('md5');
        foreach ($client->keys() as $stored) {
            if ((string) ($stored['fingerprint'] ?? '') === $fingerprint) {
                return [$fingerprint, $private];
            }
        }
        $added = $client->addKey('nebrix-wipe-' . substr(str_replace(':', '', $fingerprint), 0, 12), $public->toString('OpenSSH', ['comment' => 'nebrix-wipe']));

        return [(string) ($added['fingerprint'] ?? $fingerprint), $private];
    }

    private function privateKey(Integration $integration): string
    {
        $fresh = $this->integrationRepository->find($integration->id) ?? $integration;
        if (!$fresh->hasSecret('wipe_ssh_key')) {
            throw new IntegrationException('No SSH key for disk wiping is stored.');
        }

        return $fresh->secret('wipe_ssh_key');
    }

    /**
     * @param array<string, mixed> $wipe
     * @return list<string>
     */
    private function hostKeys(array $wipe): array
    {
        $keys = json_decode((string) ($wipe['host_keys'] ?? '[]'), true);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $wipe
     */
    private function fail(array $wipe, string $error): string
    {
        $this->update($wipe, ['status' => self::FAILED, 'error' => mb_substr($error, 0, 500), 'finished_at' => $this->clock->nowString()]);
        $this->audit->log(null, 'hetzner.wipe_failed', 'hetzner_wipe', (int) $wipe['id'], ['server' => (int) $wipe['server_number'], 'error' => mb_substr($error, 0, 200)]);
        $this->logger->error('Hetzner disk wipe failed', ['wipe' => $wipe['id'], 'server' => $wipe['server_number'], 'error' => $error]);

        return 'failed';
    }

    /**
     * @param array<string, mixed> $wipe
     * @param array<string, mixed> $changes
     */
    private function update(array $wipe, array $changes): void
    {
        $this->db->update('nx_hetzner_wipes', $changes + ['updated_at' => $this->clock->nowString()], ['id' => $wipe['id']]);
    }

    /**
     * Writes the wipe script to the rescue system and starts it in the
     * background, detached from the SSH session.
     */
    public static function startCommand(string $method): string
    {
        $script = self::script($method === 'quick' ? 'quick' : 'full');

        return "cat > /root/nebrix-wipe.sh <<'NEBRIX_WIPE_EOF'\n" . $script . "NEBRIX_WIPE_EOF\n"
            . "chmod 700 /root/nebrix-wipe.sh && echo starting > /root/nebrix-wipe.state && (nohup sh /root/nebrix-wipe.sh > /dev/null 2>&1 &)\n";
    }

    /**
     * The wipe script (POSIX sh, Hetzner rescue system). Every disk:
     * file system signatures are removed; "full" then erases NVMe drives
     * with a format (user data erase), discards SSDs and zeroes their first
     * and last 64 MiB, and overwrites HDDs completely with zeros.
     */
    public static function script(string $method): string
    {
        return <<<SH
#!/bin/sh
STATE=/root/nebrix-wipe.state
LOG=/root/nebrix-wipe.log
METHOD={$method}
echo running > "\$STATE"
: > "\$LOG"
# Release RAID arrays and volume groups the rescue system may have assembled from the old installation.
mdadm --stop --scan >> "\$LOG" 2>&1
vgchange -an >> "\$LOG" 2>&1
DISKS=\$(lsblk -dnpo NAME,TYPE | awk '\$2 == "disk" {print \$1}')
if [ -z "\$DISKS" ]; then echo "no disks found" >> "\$LOG"; echo failed > "\$STATE"; exit 1; fi
edges() {
  dd if=/dev/zero of="\$1" bs=1M count=64 oflag=direct status=none >> "\$LOG" 2>&1
  SECTORS=\$(blockdev --getsz "\$1")
  if [ "\$SECTORS" -gt 131072 ]; then
    dd if=/dev/zero of="\$1" bs=512 seek=\$((SECTORS - 131072)) count=131072 oflag=direct status=none >> "\$LOG" 2>&1
  fi
}
for DISK in \$DISKS; do
  echo "== \$DISK" >> "\$LOG"
  wipefs -af "\$DISK" >> "\$LOG" 2>&1
  if [ "\$METHOD" = "quick" ]; then edges "\$DISK"; continue; fi
  case "\$DISK" in
    /dev/nvme*)
      if nvme format "\$DISK" --ses=1 --force >> "\$LOG" 2>&1; then echo "nvme format ok" >> "\$LOG"; edges "\$DISK"; continue; fi ;;
  esac
  ROT=\$(cat "/sys/block/\$(basename "\$DISK")/queue/rotational" 2>/dev/null || echo 1)
  if [ "\$ROT" = "0" ] && blkdiscard -f "\$DISK" >> "\$LOG" 2>&1; then echo "discard ok" >> "\$LOG"; edges "\$DISK"; continue; fi
  echo "overwriting with zeros" >> "\$LOG"
  dd if=/dev/zero of="\$DISK" bs=4M oflag=direct status=none >> "\$LOG" 2>&1
  echo "overwrite finished" >> "\$LOG"
done
sync
echo done > "\$STATE"

SH;
    }
}
