<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Http\Response;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Files attached to ticket messages.
 *
 * - Allowed: PNG, JPEG, GIF, WebP images, PDF and plain text (e.g. logs),
 *   detected from the content (finfo plus a format check) – never from the
 *   name or the type the browser sent.
 * - Size and number per message are limited in the settings.
 * - Files are stored outside the document root (storage/attachments) under
 *   a random name; the original name is kept for display only.
 * - They are only served by the ticket controllers after the ownership or
 *   permission check, as attachment with nosniff and a sandbox CSP.
 */
class TicketAttachments
{
    /** detected MIME type => file extension */
    public const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    private const IMAGE_TYPES = ['image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG, 'image/gif' => IMAGETYPE_GIF, 'image/webp' => IMAGETYPE_WEBP];

    /**
     * @param string|null $directory storage directory (default: storage/attachments)
     * @param bool $requireHttpUpload false only in tests (files that did not come from an HTTP upload)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?string $directory = null,
        private readonly bool $requireHttpUpload = true,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('support.attachments_enabled');
    }

    public function maxBytes(): int
    {
        return max(1, min(25, $this->settings->int('support.attachment_max_mb') ?: 5)) * 1024 * 1024;
    }

    public function maxFiles(): int
    {
        return max(1, min(10, $this->settings->int('support.attachment_max_files') ?: 3));
    }

    /**
     * Normalizes the $_FILES entry of a multiple file field ("attachments[]")
     * and drops empty slots.
     *
     * @param array<string, mixed>|null $field
     * @return list<array{name: string, tmp_name: string, error: int, size: int}>
     */
    public static function uploadedFiles(?array $field): array
    {
        if ($field === null) {
            return [];
        }
        $files = [];
        if (is_array($field['name'] ?? null)) {
            foreach (array_keys($field['name']) as $index) {
                $files[] = [
                    'name' => (string) ($field['name'][$index] ?? ''),
                    'tmp_name' => (string) ($field['tmp_name'][$index] ?? ''),
                    'error' => (int) ($field['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($field['size'][$index] ?? 0),
                ];
            }
        } else {
            $files[] = ['name' => (string) ($field['name'] ?? ''), 'tmp_name' => (string) ($field['tmp_name'] ?? ''), 'error' => (int) ($field['error'] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($field['size'] ?? 0)];
        }

        return array_values(array_filter($files, static fn (array $file): bool => $file['error'] !== UPLOAD_ERR_NO_FILE));
    }

    /**
     * Checks all files of one message before anything is stored.
     *
     * @param list<array{name: string, tmp_name: string, error: int, size: int}> $files
     * @return list<array{name: string, tmp_name: string, mime: string, size: int}>
     *
     * @throws RuntimeException with a message for the user
     */
    public function validate(array $files): array
    {
        if ($files === []) {
            return [];
        }
        if (!$this->enabled()) {
            throw new RuntimeException('Attachments are not enabled.');
        }
        if (count($files) > $this->maxFiles()) {
            throw new RuntimeException('Too many attachments for one message.');
        }
        $checked = [];
        foreach ($files as $file) {
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException('An attachment is larger than allowed.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The upload failed.');
            }
            $tmp = $file['tmp_name'];
            if ($tmp === '' || !is_file($tmp) || ($this->requireHttpUpload && !is_uploaded_file($tmp))) {
                throw new RuntimeException('The upload failed.');
            }
            $size = filesize($tmp);
            if ($size === false || $size === 0) {
                throw new RuntimeException('The file is empty.');
            }
            if ($size > $this->maxBytes()) {
                throw new RuntimeException('An attachment is larger than allowed.');
            }
            $checked[] = ['name' => self::displayName($file['name']), 'tmp_name' => $tmp, 'mime' => $this->detect($tmp), 'size' => $size];
        }

        return $checked;
    }

    /**
     * Stores validated files for a message.
     *
     * @param list<array{name: string, tmp_name: string, mime: string, size: int}> $files from validate()
     */
    public function store(int $ticketId, ?int $messageId, ?int $userId, bool $isStaff, array $files): int
    {
        $stored = 0;
        foreach ($files as $file) {
            $directory = $this->path();
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('The attachment directory is not writable.');
            }
            $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$file['mime']];
            $target = $directory . '/' . $name;
            $moved = $this->requireHttpUpload ? move_uploaded_file($file['tmp_name'], $target) : rename($file['tmp_name'], $target);
            if (!$moved) {
                throw new RuntimeException('The file could not be saved.');
            }
            chmod($target, 0640);
            $this->db->insert('nx_ticket_attachments', [
                'ticket_id' => $ticketId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'is_staff' => $isStaff ? 1 : 0,
                'original_name' => $file['name'],
                'stored_name' => $name,
                'mime' => $file['mime'],
                'size' => $file['size'],
                'sha256' => (string) hash_file('sha256', $target),
                'created_at' => $this->clock->nowString(),
            ]);
            $stored++;
        }

        return $stored;
    }

    /**
     * Attachments of a ticket grouped by message ID.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function forTicket(int $ticketId): array
    {
        $grouped = [];
        foreach ($this->db->fetchAll('SELECT `id`, `message_id`, `original_name`, `mime`, `size`, `is_staff`, `created_at` FROM `nx_ticket_attachments` WHERE `ticket_id` = ? ORDER BY `id`', [$ticketId]) as $row) {
            $grouped[(int) ($row['message_id'] ?? 0)][] = $row;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $ticketId, int $attachmentId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_ticket_attachments` WHERE `id` = ? AND `ticket_id` = ?', [$attachmentId, $ticketId]);
    }

    /**
     * Download response for an attachment the caller is allowed to see.
     *
     * @param array<string, mixed> $attachment row from find()
     */
    public function download(array $attachment): ?Response
    {
        $name = (string) $attachment['stored_name'];
        if (preg_match('/^[a-f0-9]{32}\.(png|jpg|gif|webp|pdf|txt)$/', $name) !== 1) {
            return null;
        }
        $file = $this->path() . '/' . $name;
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false || !hash_equals((string) $attachment['sha256'], hash('sha256', $content))) {
            $this->logger->error('Ticket attachment missing or modified', ['attachment' => $attachment['id'], 'ticket' => $attachment['ticket_id']]);

            return null;
        }
        $mime = (string) $attachment['mime'];
        $response = new Response($content, 200, ['Content-Type' => $mime === 'text/plain' ? 'text/plain; charset=UTF-8' : (isset(self::TYPES[$mime]) ? $mime : 'application/octet-stream')]);
        $filename = (string) $attachment['original_name'];
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $response->setHeader('Content-Disposition', sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", $ascii, rawurlencode($filename)));
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Cache-Control', 'no-store, private');
        $response->setHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    /** Directory of the stored files (outside the document root). */
    public function path(): string
    {
        return rtrim($this->directory ?? $this->config->string('paths.base') . '/storage/attachments', '/');
    }

    /**
     * @throws RuntimeException when the content is not an allowed type
     */
    private function detect(string $file): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new RuntimeException('Only images (PNG, JPEG, GIF, WebP), PDF documents and plain text files are allowed.');
        }
        if (isset(self::IMAGE_TYPES[$mime])) {
            $info = @getimagesize($file);
            if ($info === false || $info[2] !== self::IMAGE_TYPES[$mime] || $info[0] < 1 || $info[1] < 1) {
                throw new RuntimeException('The image file is damaged or not a valid image.');
            }
        } elseif ($mime === 'application/pdf') {
            $handle = fopen($file, 'rb');
            $head = $handle !== false ? (string) fread($handle, 5) : '';
            if ($handle !== false) {
                fclose($handle);
            }
            if ($head !== '%PDF-') {
                throw new RuntimeException('The PDF file is not valid.');
            }
        } elseif (str_contains((string) file_get_contents($file), "\0")) {
            // Plain text never contains NUL bytes.
            throw new RuntimeException('Only images (PNG, JPEG, GIF, WebP), PDF documents and plain text files are allowed.');
        }

        return $mime;
    }

    /** File name for display and download: no path, no control characters, at most 150 characters. */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>]+/u', '', $name));
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = '';
        }

        return mb_substr($name !== '' ? $name : 'attachment', 0, 150);
    }
}
