<?php

declare(strict_types=1);

namespace Nebrix\Network;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Security\ValidationException;

/**
 * IPv4 and IPv6 address management for statically addressed servers.
 *
 * A pool is a network with gateway and resolvers; its usable addresses are
 * stored individually so that every address is assigned to at most one
 * service (row lock + unique key). Allocation is idempotent per service and
 * pool, so a retried provisioning job keeps its address. IPv6 pools hand out
 * single addresses of an on-link prefix (e.g. a /64), at most MAX_POOL_SIZE
 * of them from a configurable range.
 */
class IpPoolService
{
    public const MAX_POOL_SIZE = 4096;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>> pools with address counts
     */
    public function pools(): array
    {
        return $this->db->fetchAll(
            "SELECT p.*, i.`name` AS integration_name,
                    SUM(a.`status` = 'free') AS free_count, SUM(a.`status` = 'assigned') AS assigned_count, SUM(a.`status` = 'reserved') AS reserved_count
             FROM `nx_ip_pools` p LEFT JOIN `nx_integrations` i ON i.`id` = p.`integration_id` LEFT JOIN `nx_ip_addresses` a ON a.`pool_id` = p.`id`
             GROUP BY p.`id` ORDER BY p.`name`",
        );
    }

    /**
     * @param 4|6 $family
     * @return array<int, string> id => label (for product settings)
     */
    public function options(int $family = 4): array
    {
        $options = [];
        foreach ($this->db->fetchAll('SELECT `id`, `name`, `network` FROM `nx_ip_pools` WHERE (LOCATE(\':\', `network`) > 0) = ? ORDER BY `name`', [$family === 6 ? 1 : 0]) as $row) {
            $options[(int) $row['id']] = sprintf('%s (%s)', $row['name'], $row['network']);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_ip_pools` WHERE `id` = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function addresses(int $poolId): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, s.`name` AS service_name FROM `nx_ip_addresses` a LEFT JOIN `nx_services` s ON s.`id` = a.`service_id` WHERE a.`pool_id` = ? ORDER BY INET6_ATON(a.`address`)',
            [$poolId],
        );
    }

    /**
     * Creates a pool with all usable addresses of $network (or of the range
     * $first–$last inside it), excluding network, broadcast and gateway.
     *
     * @throws ValidationException
     */
    public function createPool(string $name, string $network, string $gateway, ?string $dns, ?int $integrationId, ?string $first = null, ?string $last = null, ?string $note = null): int
    {
        if (str_contains($network, ':')) {
            return $this->createIpv6Pool($name, $network, $gateway, $dns, $integrationId, $first, $last, $note);
        }
        [$base, $prefix] = self::parseNetwork($network);
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $networkLong = $base & $mask;
        $broadcast = $networkLong | (~$mask & 0xFFFFFFFF);

        $gatewayLong = ip2long($gateway);
        if ($gatewayLong === false || ($gatewayLong & $mask) !== $networkLong) {
            throw ValidationException::withMessages(['gateway' => 'The gateway must be an address inside the network.']);
        }

        $start = $prefix >= 31 ? $networkLong : $networkLong + 1;
        $end = $prefix >= 31 ? $broadcast : $broadcast - 1;
        if ($first !== null && $first !== '') {
            $value = ip2long($first);
            if ($value === false || $value < $start || $value > $end) {
                throw ValidationException::withMessages(['first' => 'The first address must be a usable address of the network.']);
            }
            $start = $value;
        }
        if ($last !== null && $last !== '') {
            $value = ip2long($last);
            if ($value === false || $value < $start || $value > $end) {
                throw ValidationException::withMessages(['last' => 'The last address must be inside the network and after the first address.']);
            }
            $end = $value;
        }
        if ($end - $start + 1 > self::MAX_POOL_SIZE) {
            throw ValidationException::withMessages(['network' => sprintf('A pool can hold at most %d addresses; limit the range.', self::MAX_POOL_SIZE)]);
        }

        $resolvers = self::parseResolvers($dns);
        $this->assertUniqueName($name);

        return $this->db->transaction(function () use ($name, $networkLong, $prefix, $gateway, $resolvers, $integrationId, $note, $start, $end, $gatewayLong): int {
            $now = $this->clock->nowString();
            $poolId = $this->db->insert('nx_ip_pools', [
                'name' => $name,
                'integration_id' => $integrationId,
                'network' => long2ip($networkLong) . '/' . $prefix,
                'gateway' => $gateway,
                'dns' => $resolvers === [] ? null : implode(',', $resolvers),
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            for ($address = $start; $address <= $end; $address++) {
                if ($address === $gatewayLong) {
                    continue;
                }
                $this->db->insert('nx_ip_addresses', ['pool_id' => $poolId, 'address' => long2ip($address), 'status' => 'free', 'updated_at' => $now]);
            }

            return $poolId;
        });
    }

    /**
     * IPv6 pool: single addresses of the prefix, from $first (default: the
     * second address of the prefix) to $last (default: as many as allowed).
     *
     * @throws ValidationException
     */
    private function createIpv6Pool(string $name, string $network, string $gateway, ?string $dns, ?int $integrationId, ?string $first, ?string $last, ?string $note): int
    {
        [$base, $prefix] = self::parseNetwork6($network);
        $mask = self::mask6($prefix);
        $networkBin = $base & $mask;
        $lastBin = $networkBin | ~$mask;

        $gatewayBin = @inet_pton($gateway);
        if ($gatewayBin === false || strlen($gatewayBin) !== 16 || (($gatewayBin & $mask) !== $networkBin && !self::isLinkLocal($gatewayBin))) {
            throw ValidationException::withMessages(['gateway' => 'The gateway must be an address inside the network or a link-local address (fe80::/10).']);
        }

        // The all-zero address of the prefix is the subnet-router anycast address.
        $start = self::add6($networkBin, 1);
        if ($first !== null && $first !== '') {
            $value = @inet_pton($first);
            if ($value === false || strlen($value) !== 16 || strcmp($value, $start) < 0 || strcmp($value, $lastBin) > 0) {
                throw ValidationException::withMessages(['first' => 'The first address must be a usable address of the network.']);
            }
            $start = $value;
        }
        $end = self::add6($start, self::MAX_POOL_SIZE - 1);
        if (strcmp($end, $lastBin) > 0 || strcmp($end, $start) < 0) {
            $end = $lastBin; // range shorter than the maximum (or wrapped at the end)
        }
        if ($last !== null && $last !== '') {
            $value = @inet_pton($last);
            if ($value === false || strlen($value) !== 16 || strcmp($value, $start) < 0 || strcmp($value, $lastBin) > 0) {
                throw ValidationException::withMessages(['last' => 'The last address must be inside the network and after the first address.']);
            }
            if (strcmp($value, $end) > 0) {
                throw ValidationException::withMessages(['network' => sprintf('A pool can hold at most %d addresses; limit the range.', self::MAX_POOL_SIZE)]);
            }
            $end = $value;
        }

        $resolvers = self::parseResolvers($dns);
        $this->assertUniqueName($name);

        return $this->db->transaction(function () use ($name, $networkBin, $prefix, $gatewayBin, $resolvers, $integrationId, $note, $start, $end): int {
            $now = $this->clock->nowString();
            $poolId = $this->db->insert('nx_ip_pools', [
                'name' => $name,
                'integration_id' => $integrationId,
                'network' => (string) inet_ntop($networkBin) . '/' . $prefix,
                'gateway' => (string) inet_ntop($gatewayBin),
                'dns' => $resolvers === [] ? null : implode(',', $resolvers),
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            for ($address = $start, $i = 0; strcmp($address, $end) <= 0 && $i < self::MAX_POOL_SIZE; $address = self::add6($address, 1), $i++) {
                if ($address !== $gatewayBin) {
                    $this->db->insert('nx_ip_addresses', ['pool_id' => $poolId, 'address' => (string) inet_ntop($address), 'status' => 'free', 'updated_at' => $now]);
                }
                if ($address === $end) {
                    break;
                }
            }

            return $poolId;
        });
    }

    /** @throws ValidationException */
    private function assertUniqueName(string $name): void
    {
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_ip_pools` WHERE `name` = ?', [$name]) > 0) {
            throw ValidationException::withMessages(['name' => 'A pool with this name already exists.']);
        }
    }

    /**
     * Assigns a free address of the pool to the service (or returns the
     * address it already has).
     *
     * @param 4|6|null $family expected address family (null = any)
     * @return array{address: string, prefix: int, gateway: string, dns: list<string>}
     *
     * @throws PoolExhaustedException
     */
    public function allocate(int $poolId, int $serviceId, ?int $family = null): array
    {
        return $this->db->transaction(function () use ($poolId, $serviceId, $family): array {
            $pool = $this->db->fetchOne('SELECT * FROM `nx_ip_pools` WHERE `id` = ?', [$poolId]) ?? throw new PoolExhaustedException('The IP pool no longer exists.');
            if ($family !== null && (str_contains((string) $pool['network'], ':') ? 6 : 4) !== $family) {
                throw new PoolExhaustedException(sprintf('The IP pool "%s" is not an IPv%d pool.', $pool['name'], $family));
            }
            $address = $this->db->fetchValue("SELECT `address` FROM `nx_ip_addresses` WHERE `pool_id` = ? AND `service_id` = ? AND `status` = 'assigned'", [$poolId, $serviceId]);

            if (!is_string($address)) {
                $row = $this->db->fetchOne("SELECT `id`, `address` FROM `nx_ip_addresses` WHERE `pool_id` = ? AND `status` = 'free' ORDER BY `id` LIMIT 1 FOR UPDATE SKIP LOCKED", [$poolId]);
                if ($row === null) {
                    throw new PoolExhaustedException(sprintf('The IP pool "%s" has no free addresses left.', $pool['name']));
                }
                $now = $this->clock->nowString();
                $this->db->update('nx_ip_addresses', ['status' => 'assigned', 'service_id' => $serviceId, 'assigned_at' => $now, 'updated_at' => $now], ['id' => (int) $row['id']]);
                $address = (string) $row['address'];
            }

            return [
                'address' => $address,
                'prefix' => (int) explode('/', (string) $pool['network'])[1],
                'gateway' => (string) $pool['gateway'],
                'dns' => self::parseResolvers((string) ($pool['dns'] ?? '')),
            ];
        });
    }

    /** Returns every address of the service to its pool. */
    public function release(int $serviceId): int
    {
        return $this->db->run(
            "UPDATE `nx_ip_addresses` SET `status` = 'free', `service_id` = NULL, `assigned_at` = NULL, `updated_at` = ? WHERE `service_id` = ? AND `status` = 'assigned'",
            [$this->clock->nowString(), $serviceId],
        )->rowCount();
    }

    /**
     * @return list<string>
     */
    public function addressesOf(int $serviceId): array
    {
        return array_map(static fn (array $row): string => (string) $row['address'], $this->db->fetchAll("SELECT `address` FROM `nx_ip_addresses` WHERE `service_id` = ? AND `status` = 'assigned' ORDER BY `id`", [$serviceId]));
    }

    /**
     * Marks an unassigned address as reserved (not handed out) or free.
     *
     * @throws ValidationException
     */
    public function setReserved(int $poolId, int $addressId, bool $reserved, ?string $note): void
    {
        $changed = $this->db->run(
            "UPDATE `nx_ip_addresses` SET `status` = ?, `note` = ?, `updated_at` = ? WHERE `id` = ? AND `pool_id` = ? AND `status` IN ('free', 'reserved')",
            [$reserved ? 'reserved' : 'free', $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, $this->clock->nowString(), $addressId, $poolId],
        )->rowCount();
        if ($changed === 0 && $this->db->fetchValue("SELECT `status` FROM `nx_ip_addresses` WHERE `id` = ? AND `pool_id` = ?", [$addressId, $poolId]) === 'assigned') {
            throw ValidationException::withMessages(['address' => 'Assigned addresses cannot be changed; terminate the service first.']);
        }
    }

    /**
     * @throws ValidationException
     */
    public function deletePool(int $poolId): void
    {
        if ((int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_ip_addresses` WHERE `pool_id` = ? AND `status` = 'assigned'", [$poolId]) > 0) {
            throw ValidationException::withMessages(['pool' => 'The pool still has assigned addresses.']);
        }
        $this->db->transaction(function () use ($poolId): void {
            $this->db->delete('nx_ip_addresses', ['pool_id' => $poolId]);
            $this->db->delete('nx_ip_pools', ['id' => $poolId]);
        });
    }

    /**
     * @return array{0: int, 1: int} network address as integer, prefix length
     *
     * @throws ValidationException
     */
    public static function parseNetwork(string $network): array
    {
        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($network), $match) !== 1 || ip2long($match[1]) === false || (int) $match[2] < 16 || (int) $match[2] > 32) {
            throw ValidationException::withMessages(['network' => 'Enter an IPv4 network in CIDR notation between /16 and /32, e.g. 203.0.113.0/24.']);
        }

        return [(int) ip2long($match[1]), (int) $match[2]];
    }

    /**
     * @return array{0: string, 1: int} network address (16 bytes), prefix length
     *
     * @throws ValidationException
     */
    public static function parseNetwork6(string $network): array
    {
        $parts = explode('/', trim($network));
        $binary = count($parts) === 2 ? @inet_pton($parts[0]) : false;
        if ($binary === false || strlen($binary) !== 16 || preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] < 48 || (int) $parts[1] > 128) {
            throw ValidationException::withMessages(['network' => 'Enter an IPv6 network in CIDR notation between /48 and /128, e.g. 2001:db8:1::/64.']);
        }

        return [$binary, (int) $parts[1]];
    }

    /** 16-byte mask with the first $prefix bits set. */
    private static function mask6(int $prefix): string
    {
        $mask = '';
        for ($byte = 0; $byte < 16; $byte++) {
            $bits = max(0, min(8, $prefix - 8 * $byte));
            $mask .= chr($bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF);
        }

        return $mask;
    }

    /** Adds a small non-negative number to a 16-byte address (wraps at the end of the address space). */
    private static function add6(string $address, int $value): string
    {
        $bytes = array_values(unpack('C16', $address) ?: []);
        for ($i = 15; $i >= 0 && $value > 0; $i--) {
            $sum = $bytes[$i] + ($value & 0xFF);
            $bytes[$i] = $sum & 0xFF;
            $value = ($value >> 8) + ($sum >> 8);
        }

        return pack('C16', ...$bytes);
    }

    private static function isLinkLocal(string $address): bool
    {
        return (ord($address[0]) === 0xFE) && ((ord($address[1]) & 0xC0) === 0x80);
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    public static function parseResolvers(?string $dns): array
    {
        $resolvers = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $dns) ?: [])));
        foreach ($resolvers as $resolver) {
            if (filter_var($resolver, FILTER_VALIDATE_IP) === false) {
                throw ValidationException::withMessages(['dns' => sprintf('"%s" is not a valid IP address.', mb_substr($resolver, 0, 50))]);
            }
        }

        return array_slice($resolvers, 0, 3);
    }
}
