<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Core\Container;
use Nebrix\Database\Connection;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\Csrf;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\TicketAttachments;
use Psr\Log\LoggerInterface;

/**
 * Helpers for the support feature tests: attachments in a temporary
 * directory (files that did not come from a real HTTP upload are accepted
 * only here) and multipart requests.
 */
trait SupportHelpers
{
    protected string $attachmentDir = '';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function useTemporaryAttachments(): void
    {
        $this->attachmentDir = sys_get_temp_dir() . '/nebrix-test-attachments-' . bin2hex(random_bytes(6));
        $dir = $this->attachmentDir;
        $this->app->container()->set(TicketAttachments::class, static fn (Container $c): TicketAttachments => new TicketAttachments(
            $c->get(Connection::class),
            $c->get(Clock::class),
            $c->get(SettingsRepository::class),
            $c->get(Config::class),
            $c->get(LoggerInterface::class),
            $dir,
            false,
        ));
    }

    protected function removeTemporaryFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($this->attachmentDir !== '' && is_dir($this->attachmentDir)) {
            foreach (glob($this->attachmentDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->attachmentDir);
        }
    }

    /**
     * A temporary file as an entry of the "attachments[]" upload field.
     *
     * @return array{name: string, tmp_name: string, error: int, size: int, type: string}
     */
    protected function uploadedFile(string $name, string $content, string $claimedType = 'application/octet-stream'): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'nx-up-');
        file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return ['name' => $name, 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'type' => $claimedType];
    }

    protected static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    }

    /**
     * POST with uploaded files in the multiple-file format of $_FILES.
     *
     * @param array<string, mixed> $data
     * @param list<array{name: string, tmp_name: string, error: int, size: int, type: string}> $files
     */
    protected function postWithFiles(string $uri, array $data, array $files): Response
    {
        $data[Csrf::FIELD] ??= $this->csrfToken();
        $field = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as $file) {
            foreach (array_keys($field) as $key) {
                $field[$key][] = $file[$key];
            }
        }

        return $this->send(new Request('POST', $uri, [], $data, $this->cookies, ['attachments' => $field], [
            'REMOTE_ADDR' => $this->clientIp, 'HTTP_HOST' => 'localhost', 'HTTP_USER_AGENT' => 'NebrixTest/1.0',
        ]));
    }
}
