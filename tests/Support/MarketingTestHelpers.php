<?php

declare(strict_types=1);

namespace Nebrix\Tests\Support;

use Nebrix\Auth\User;
use Nebrix\Core\Config;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\MediaStore;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\Csrf;

/**
 * Helpers for the marketing feature tests (use in FeatureTestCase subclasses):
 * staff with chosen permissions, file uploads, products, a temporary image store.
 */
trait MarketingTestHelpers
{
    private string $mediaDir = '';

    /** Routes the image store to a temporary directory and accepts files that were not uploaded through PHP. */
    protected function useTemporaryMediaStore(): void
    {
        $this->mediaDir = sys_get_temp_dir() . '/nebrix-marketing-' . bin2hex(random_bytes(4));
        $dir = $this->mediaDir;
        $this->app->container()->set(MediaStore::class, static fn ($c): MediaStore => new MediaStore($c->get(Config::class), $dir, false));
    }

    protected function removeTemporaryMediaStore(): void
    {
        if ($this->mediaDir !== '' && is_dir($this->mediaDir)) {
            foreach (glob($this->mediaDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->mediaDir);
        }
    }

    /**
     * A staff member whose role holds exactly these permissions (plus admin.access).
     *
     * @param list<string> $permissions
     */
    protected function staffWith(array $permissions): User
    {
        $slug = 'test-' . bin2hex(random_bytes(4));
        $now = $this->clock()->nowString();
        $roleId = $this->db->insert('nx_roles', ['slug' => $slug, 'name' => $slug, 'is_system' => 0, 'is_staff' => 1, 'created_at' => $now, 'updated_at' => $now]);
        foreach (array_unique(['admin.access', ...$permissions]) as $permission) {
            $this->db->insert('nx_role_permissions', ['role_id' => $roleId, 'permission' => $permission]);
        }

        return $this->createUser($slug);
    }

    /**
     * POST with an uploaded file (multipart form).
     *
     * @param array<string, mixed> $data
     */
    protected function postFile(string $uri, string $field, string $content, array $data = []): Response
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);
        $data[Csrf::FIELD] = $this->csrfToken();

        return $this->send(new Request('POST', $uri, [], $data, $this->cookies, [$field => ['name' => 'upload.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]], [
            'REMOTE_ADDR' => $this->clientIp, 'HTTP_HOST' => 'localhost', 'HTTP_USER_AGENT' => 'NebrixTest/1.0',
        ]));
    }

    protected function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $extra
     */
    protected function websiteProduct(string $category, string $name, int $price, array $config = [], array $extra = []): int
    {
        return $this->app->container()->get(ProductRepository::class)->create($extra + [
            'category' => $category, 'name' => $name, 'slug' => strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . random_int(1, 99999), 'price' => $price, 'setup_fee' => 0,
            'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => $config,
            'features' => "Web interface\nBackups",
        ]);
    }

    /**
     * The JSON-LD graph of a page.
     *
     * @return list<array<string, mixed>>
     */
    protected function jsonLd(string $html): array
    {
        if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match) !== 1) {
            return [];
        }
        $data = json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);

        return is_array($data) && is_array($data['@graph'] ?? null) ? $data['@graph'] : [];
    }

    /**
     * @param list<array<string, mixed>> $graph
     * @return list<array<string, mixed>>
     */
    protected function nodes(array $graph, string $type): array
    {
        return array_values(array_filter($graph, static fn (array $node): bool => ($node['@type'] ?? null) === $type));
    }
}
