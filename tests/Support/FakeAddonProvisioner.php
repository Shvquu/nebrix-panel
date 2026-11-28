<?php

declare(strict_types=1);

namespace Nebrix\Tests\Support;

use Nebrix\Service\Addon\AddonProvisioner;

/**
 * Add-on provisioner for tests: supports one kind, hands out documentation
 * addresses (203.0.113.0/24) and records every call. Idempotent like a real
 * one: provisioning an add-on twice returns the same address.
 */
final class FakeAddonProvisioner implements AddonProvisioner
{
    /** @var list<array{0: string, 1: int, 2: int}> [action, service ID, add-on ID] */
    public array $calls = [];

    /** @var array<int, string> add-on ID => address */
    private array $addresses = [];

    public ?\Throwable $failWith = null;

    public function __construct(private readonly string $kind = 'ipv4')
    {
    }

    public function supports(string $kind, array $service): bool
    {
        return $kind === $this->kind && $service['category'] === 'lxc';
    }

    public function provision(array $service, array $addon): array
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->calls[] = ['provision', (int) $service['id'], (int) $addon['id']];
        $this->addresses[(int) $addon['id']] ??= '203.0.113.' . (count($this->addresses) + 10);

        return ['address' => $this->addresses[(int) $addon['id']]];
    }

    public function deprovision(array $service, array $addon): void
    {
        $this->calls[] = ['deprovision', (int) $service['id'], (int) $addon['id']];
        unset($this->addresses[(int) $addon['id']]);
    }
}
