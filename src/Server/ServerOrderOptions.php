<?php

declare(strict_types=1);

namespace Nebrix\Server;

use Nebrix\Auth\User;
use Nebrix\Server\SshKeys\SshKeyRepository;
use Nebrix\Server\Templates\ServerTemplateRepository;

/**
 * Choices of the order page of Proxmox servers: the templates the product
 * offers and the customer's own SSH keys.
 */
class ServerOrderOptions
{
    public function __construct(
        private readonly ServerTemplateRepository $templates,
        private readonly SshKeyRepository $keys,
    ) {
    }

    /**
     * @param array<string, mixed> $product
     * @return array{templates: list<array<string, mixed>>, keys: array<int, string>}
     */
    public function forProduct(array $product, User $user): array
    {
        if (ServerTemplateRepository::typeFor((string) ($product['category'] ?? '')) === null) {
            return ['templates' => [], 'keys' => []];
        }

        return [
            'templates' => $this->templates->offeredBy((int) $product['id']),
            'keys' => $this->keys->options($user->id),
        ];
    }
}
