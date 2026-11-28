<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Core\Config;
use RuntimeException;

/**
 * Images of the marketing area (blog cover images, the preview image for
 * social networks).
 *
 * - Only PNG and JPEG, detected from the content (finfo and the image
 *   header) – never from the name or type the browser sent; max. 2 MB and
 *   4096×4096 pixels.
 * - Stored under storage/uploads/marketing (outside the document root) with
 *   a random name; served by MediaController only while the image belongs
 *   to published content (staff with content.view also see drafts).
 */
class MediaStore
{
    public const MAX_BYTES = 2_097_152;

    /** Generated file names: 32 hex characters and the detected extension. */
    public const NAME_PATTERN = '/^[a-f0-9]{32}\.(png|jpg)$/';

    private const TYPES = [
        'image/png' => ['png', IMAGETYPE_PNG],
        'image/jpeg' => ['jpg', IMAGETYPE_JPEG],
    ];

    private readonly string $directory;

    /**
     * @param bool $requireHttpUpload false only in tests (files that were not uploaded through PHP)
     */
    public function __construct(
        Config $config,
        ?string $directory = null,
        private readonly bool $requireHttpUpload = true,
    ) {
        $this->directory = rtrim($directory ?? $config->string('paths.base') . '/storage/uploads/marketing', '/');
    }

    /**
     * @param array<string, mixed> $file entry from $_FILES
     * @return string the generated file name
     *
     * @throws RuntimeException with a message that can be shown to staff
     */
    public function store(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($error === UPLOAD_ERR_NO_FILE ? 'Please choose a file.' : 'The upload failed.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($this->requireHttpUpload && !is_uploaded_file($tmp))) {
            throw new RuntimeException('The upload failed.');
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('The image must be smaller than 2 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new RuntimeException('Only PNG or JPEG images are allowed.');
        }
        [$extension, $imageType] = self::TYPES[$mime];
        $info = @getimagesize($tmp);
        if ($info === false || $info[2] !== $imageType || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096) {
            throw new RuntimeException('The file is not a valid image (max. 4096×4096 pixels).');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('The upload directory is not writable.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $this->directory . '/' . $name;
        $moved = $this->requireHttpUpload ? move_uploaded_file($tmp, $target) : rename($tmp, $target);
        if (!$moved) {
            throw new RuntimeException('The file could not be saved.');
        }
        chmod($target, 0640);

        return $name;
    }

    /** Absolute path of a stored image, or null for unknown or malformed names. */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $file = $this->directory . '/' . $name;

        return is_file($file) ? $file : null;
    }

    public static function mime(string $name): string
    {
        return str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg';
    }

    /** Deletes a stored image; other names are ignored. */
    public function delete(?string $name): void
    {
        $file = $name !== null ? $this->path($name) : null;
        if ($file !== null) {
            unlink($file);
        }
    }
}
