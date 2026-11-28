<?php

declare(strict_types=1);

namespace Nebrix\Notification;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Queue\JobHandler;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Security\UnsafeUrlException;
use Nebrix\Security\UrlGuard;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Posts one staff notification to the Discord channel webhook:
 * {"content": "…"}.
 *
 * The webhook URL is a secret setting; it must be a
 * https://discord.com/api/webhooks/… address, is checked by the UrlGuard
 * before every request and the resolved IP is pinned. Redirects are not
 * followed; mentions are never resolved. The URL (it contains the webhook
 * token) is never logged.
 */
final class StaffWebhookJob implements JobHandler
{
    public const URL_PATTERN = '#^https://discord\.com/api/webhooks/\d{17,20}/[A-Za-z0-9_-]{30,100}$#';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ClientInterface $http,
        private readonly UrlGuard $guard,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $content = mb_substr(trim((string) ($payload['content'] ?? '')), 0, 1900);
        $url = $this->settings->string('notifications.staff_webhook_url');
        if ($content === '' || $url === '') {
            return;
        }
        if (preg_match(self::URL_PATTERN, $url) !== 1) {
            throw new PermanentJobFailure('The staff webhook URL is not a Discord webhook address.');
        }
        try {
            $target = $this->guard->validateUrl($url);
        } catch (UnsafeUrlException $exception) {
            throw new PermanentJobFailure('The staff webhook URL was rejected: ' . $exception->getMessage());
        }

        try {
            $response = $this->http->request('POST', $url, [
                'json' => ['content' => $content, 'allowed_mentions' => ['parse' => []]],
                'headers' => ['User-Agent' => 'NebrixPanel/2.0'],
                'timeout' => 10,
                'connect_timeout' => 5,
                'allow_redirects' => false,
                'http_errors' => false,
                'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])]],
            ]);
        } catch (GuzzleException $exception) {
            $this->logger->warning('Staff Discord notification: webhook not reachable', ['error' => get_class($exception)]);

            throw new \RuntimeException('Discord is not reachable.');
        }

        $status = $response->getStatusCode();
        if ($status === 429) {
            $json = json_decode((string) $response->getBody(), true);

            throw new RetryLaterException('Discord rate limit.', max(1, (int) ceil((float) (is_array($json) ? ($json['retry_after'] ?? 5) : 5))));
        }
        if ($status >= 500) {
            throw new \RuntimeException(sprintf('Discord answered HTTP %d.', $status));
        }
        if ($status >= 300) {
            $this->logger->warning('Staff Discord notification refused', ['status' => $status]);

            throw new PermanentJobFailure(sprintf('Discord refused the staff notification (HTTP %d). Check the webhook URL.', $status));
        }
    }
}
