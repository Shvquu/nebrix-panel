<?php

declare(strict_types=1);

namespace Nebrix\Integration;

/**
 * Supported integration types and the schema of their configuration.
 * "config" values are stored as JSON; "secrets" are encrypted and never
 * displayed again after saving.
 */
final class IntegrationType
{
    public const PROXMOX = 'proxmox';
    public const PTERODACTYL = 'pterodactyl';
    public const NEXTCLOUD = 'nextcloud';
    public const TEAMSPEAK = 'teamspeak';
    public const HETZNER_ROBOT = 'hetzner_robot';
    public const PLESK = 'plesk';
    public const INWX = 'inwx';
    public const SKRIME = 'skrime';

    /**
     * @return array<string, array{label: string, description: string, config: array<string, array{label: string, rules: string, default?: mixed, help?: string, type?: string}>, secrets: array<string, array{label: string, rules: string, help?: string, required?: bool}>}>
     */
    public static function all(): array
    {
        return [
            self::PROXMOX => [
                'label' => 'Proxmox VE',
                'description' => 'KVM virtual machines and LXC containers on a Proxmox VE node or cluster.',
                'config' => [
                    'base_url' => ['label' => 'API URL', 'rules' => 'required|url|max:255', 'help' => 'e.g. https://pve.example.com:8006 – one entry per cluster.'],
                    'token_id' => ['label' => 'API token ID', 'rules' => 'required|string|max:200|regex:/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+![A-Za-z0-9._-]+$/', 'help' => 'Format user@realm!tokenname. Grant the token only the privileges it needs (see INTEGRATIONS.md).'],
                    'verify_tls' => ['label' => 'Verify TLS certificate', 'rules' => 'bool', 'default' => true, 'type' => 'bool', 'help' => 'Only disable for lab systems with self-signed certificates.'],
                    'lxc_ssh_nodes' => ['label' => 'SSH access to the nodes for LXC root passwords (optional, one per line: node=address[:port])', 'rules' => 'nullable|string|max:2000', 'type' => 'text', 'help' => 'e.g. pve1=10.0.0.11 – Proxmox has no API for changing a container password. Empty = LXC passwords change only with a reinstall.'],
                    'lxc_ssh_host_keys' => ['label' => 'SSH host keys of these nodes (output of ssh-keyscan)', 'rules' => 'nullable|string|max:20000', 'type' => 'text', 'help' => 'Required with SSH access; the panel connects only to nodes presenting one of these keys.'],
                    'lxc_ssh_user' => ['label' => 'SSH user on the nodes', 'rules' => 'nullable|string|max:32|regex:/^[a-z_][a-z0-9_-]*$/', 'default' => 'root'],
                ],
                'secrets' => [
                    'token_secret' => ['label' => 'API token secret', 'rules' => 'regex:/^[0-9a-fA-F-]{36}$/', 'required' => true],
                    // Used only for SSH to the configured nodes (forced command), never sent to the API.
                    'lxc_ssh_key' => ['label' => 'Private SSH key for LXC root passwords (optional)', 'rules' => 'string|max:5000', 'endpoint_bound' => false, 'format' => 'ssh_private_key', 'help' => 'Leave empty: the panel creates a key; the line for authorized_keys is shown on the integration page.'],
                ],
            ],
            self::PTERODACTYL => [
                'label' => 'Pterodactyl',
                'description' => 'Game servers through the Pterodactyl Application API.',
                'config' => [
                    'base_url' => ['label' => 'Panel URL', 'rules' => 'required|url|max:255', 'help' => 'e.g. https://games.example.com'],
                    'api_version' => ['label' => 'Panel version', 'rules' => 'required|in:auto,1,2', 'default' => 'auto', 'type' => 'select', 'options' => ['auto' => 'Detect automatically', '1' => 'Pterodactyl 1.x', '2' => 'Pterodactyl 2.x'], 'help' => '2.x has no nests: eggs are addressed by their ID alone.'],
                    'verify_tls' => ['label' => 'Verify TLS certificate', 'rules' => 'bool', 'default' => true, 'type' => 'bool'],
                ],
                'secrets' => [
                    'application_api_key' => ['label' => 'Application API key (ptla_…)', 'rules' => 'regex:/^ptla_[A-Za-z0-9]{20,}$/', 'required' => true],
                    'client_api_key' => ['label' => 'Client API key of a root admin (ptlc_…, optional)', 'rules' => 'regex:/^ptlc_[A-Za-z0-9]{20,}$/', 'help' => 'Enables live status and power actions in the customer panel.'],
                ],
            ],
            self::NEXTCLOUD => [
                'label' => 'Nextcloud',
                'description' => 'Storage accounts through the Nextcloud OCS provisioning API.',
                'config' => [
                    'base_url' => ['label' => 'Nextcloud URL', 'rules' => 'required|url|max:255', 'help' => 'e.g. https://cloud.example.com'],
                    'admin_user' => ['label' => 'Admin user name', 'rules' => 'required|string|max:64|regex:/^[A-Za-z0-9._@-]+$/'],
                    'user_prefix' => ['label' => 'User ID prefix', 'rules' => 'required|string|max:16|regex:/^[a-z][a-z0-9_-]*$/', 'default' => 'nx', 'help' => 'Accounts are created as <prefix><service-id>.'],
                    'verify_tls' => ['label' => 'Verify TLS certificate', 'rules' => 'bool', 'default' => true, 'type' => 'bool'],
                ],
                'secrets' => [
                    'admin_password' => ['label' => 'Admin app password', 'rules' => 'string|max:255', 'required' => true, 'help' => 'Create an app password under Settings → Security in Nextcloud.'],
                ],
            ],
            self::TEAMSPEAK => [
                'label' => 'TeamSpeak 3',
                'description' => 'Virtual TeamSpeak 3 servers through the ServerQuery interface.',
                'config' => [
                    'host' => ['label' => 'ServerQuery host', 'rules' => 'required|hostname|max:253'],
                    'query_protocol' => ['label' => 'ServerQuery connection', 'rules' => 'required|in:raw,ssh', 'default' => 'raw', 'type' => 'select', 'options' => ['raw' => 'Raw TCP (unencrypted, default port 10011)', 'ssh' => 'SSH (encrypted, default port 10022, TeamSpeak 3.3 or newer)']],
                    'query_port' => ['label' => 'ServerQuery port', 'rules' => 'required|int|min:1|max:65535', 'default' => 10011, 'help' => 'Raw: 10011, SSH: 10022 (TeamSpeak defaults).'],
                    'query_host_keys' => ['label' => 'SSH host key of the ServerQuery interface (only for SSH)', 'rules' => 'nullable|string|max:5000', 'type' => 'text', 'help' => 'Output of: ssh-keyscan -p 10022 <host> – the panel connects only to a server presenting this key.'],
                    'query_user' => ['label' => 'ServerQuery login', 'rules' => 'required|string|max:64|regex:/^[A-Za-z0-9._-]+$/', 'default' => 'serveradmin'],
                    'voice_host' => ['label' => 'Public host name for customers', 'rules' => 'required|hostname|max:253'],
                    'port_min' => ['label' => 'First voice port', 'rules' => 'required|int|min:1024|max:65535', 'default' => 9987],
                    'port_max' => ['label' => 'Last voice port', 'rules' => 'required|int|min:1024|max:65535', 'default' => 10986],
                ],
                'secrets' => [
                    'query_password' => ['label' => 'ServerQuery password', 'rules' => 'string|max:255', 'required' => true, 'help' => 'Raw ServerQuery is not encrypted: use SSH, or connect only over a private network or VPN.'],
                ],
            ],
            self::HETZNER_ROBOT => [
                'label' => 'Hetzner Robot (dedicated servers)',
                'description' => 'Dedicated root servers of a Hetzner Robot account: assignment from a server pool or ordering, installation, resets, rescue system, firewall and reverse DNS.',
                'config' => [
                    'base_url' => ['label' => 'Robot API URL', 'rules' => 'required|url|max:255', 'default' => 'https://robot-ws.your-server.de'],
                    'username' => ['label' => 'Webservice user', 'rules' => 'required|string|max:100|regex:/^[A-Za-z0-9#._@+-]+$/', 'help' => 'Create a separate webservice user in Robot (Settings → Webservice and app settings).'],
                    'pool_prefix' => ['label' => 'Server name prefix of the free pool', 'rules' => 'required|string|max:40|regex:/^[a-z0-9][a-z0-9-]{2,39}$/', 'default' => 'nebrix-free', 'help' => 'Only servers whose Robot name starts with this prefix are assigned to customers.'],
                    'allow_orders' => ['label' => 'Allow ordering new servers', 'rules' => 'bool', 'default' => false, 'type' => 'bool', 'help' => 'Products in "order" mode buy a new server at Hetzner for every order. This causes costs.'],
                    'order_test_mode' => ['label' => 'Send orders in test mode', 'rules' => 'bool', 'default' => true, 'type' => 'bool', 'help' => 'Hetzner validates test orders without processing them.'],
                    'cancel_on_terminate' => ['label' => 'Cancel ordered servers when the service is terminated', 'rules' => 'bool', 'default' => false, 'type' => 'bool'],
                    'ssh_key_fingerprint' => ['label' => 'SSH key fingerprint for orders (optional)', 'rules' => 'nullable|regex:/^([0-9a-f]{2}:){15}[0-9a-f]{2}$/', 'help' => 'Fingerprint of a key stored in Robot; used for newly ordered servers before the customer installation.'],
                    'auto_wipe' => ['label' => 'Wipe disks of returned servers automatically', 'rules' => 'bool', 'default' => false, 'type' => 'bool', 'help' => 'Terminated servers boot the rescue system with the panel\'s own SSH key, all disks are erased, then the server returns to the pool. Without this option staff wipe servers named "nebrix-wipe-<number>" by hand.'],
                    'wipe_method' => ['label' => 'Wipe method', 'rules' => 'required|in:full,quick', 'default' => 'full', 'type' => 'select', 'options' => ['full' => 'Full: NVMe format / discard of SSDs, overwrite of HDDs (can take hours)', 'quick' => 'Quick: remove partition tables and file system signatures only']],
                    'verify_tls' => ['label' => 'Verify TLS certificate', 'rules' => 'bool', 'default' => true, 'type' => 'bool'],
                ],
                'secrets' => [
                    'password' => ['label' => 'Webservice password', 'rules' => 'string|max:255', 'required' => true],
                    // Used only for SSH to the panel's own rescue systems, never sent to the Robot API.
                    'wipe_ssh_key' => ['label' => 'Private SSH key for disk wiping (optional)', 'rules' => 'string|max:5000', 'endpoint_bound' => false, 'format' => 'ssh_private_key', 'help' => 'Leave empty: the panel creates a key and stores its public part in Robot when the first server is wiped.'],
                ],
            ],
            self::PLESK => [
                'label' => 'Plesk (webhosting)',
                'description' => 'Webhosting subscriptions on a Plesk server through the XML API.',
                'config' => [
                    'base_url' => ['label' => 'Plesk URL', 'rules' => 'required|url|max:255', 'help' => 'e.g. https://web1.example.com:8443'],
                    'ip_address' => ['label' => 'Shared IPv4 address for new subscriptions', 'rules' => 'required|ip'],
                    'verify_tls' => ['label' => 'Verify TLS certificate', 'rules' => 'bool', 'default' => true, 'type' => 'bool'],
                ],
                'secrets' => [
                    'secret_key' => ['label' => 'API secret key', 'rules' => 'string|max:255', 'required' => true, 'help' => 'Create it on the Plesk server: plesk bin secret_key --create -ip-address <panel IP> -description Nebrix'],
                ],
            ],
            self::INWX => [
                'label' => 'INWX (domains)',
                'description' => 'Domain registration and transfers through the INWX DomRobot API.',
                'config' => [
                    'environment' => ['label' => 'Environment', 'rules' => 'required|in:ote,live', 'default' => 'ote', 'type' => 'select', 'options' => ['ote' => 'OTE test system (no costs)', 'live' => 'Live']],
                    'username' => ['label' => 'INWX user name', 'rules' => 'required|string|max:100|regex:/^[A-Za-z0-9._@-]+$/'],
                    'nameservers' => ['label' => 'Default name servers (one per line)', 'rules' => 'required|string|max:1000', 'default' => "ns.inwx.de\nns2.inwx.de\nns3.inwx.eu", 'type' => 'text'],
                    'verify_tls' => ['label' => 'Verify TLS certificate', 'rules' => 'bool', 'default' => true, 'type' => 'bool'],
                ],
                'secrets' => [
                    'password' => ['label' => 'INWX password', 'rules' => 'string|max:255', 'required' => true],
                    'totp_secret' => ['label' => 'Two-factor secret (only if 2FA is enabled for the account)', 'rules' => 'regex:/^[A-Z2-7]{16,64}$/', 'help' => 'Base32 secret of the INWX two-factor authentication; codes are generated automatically.'],
                ],
            ],
            self::SKRIME => [
                'label' => 'SKRIME (domains)',
                'description' => 'Domain registration and transfers through the SKRIME API (paid from the SKRIME account balance).',
                'config' => [
                    'environment' => ['label' => 'Environment', 'rules' => 'required|in:sandbox,live', 'default' => 'sandbox', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox (validates, changes nothing, no costs)', 'live' => 'Live']],
                    'nameservers' => ['label' => 'Default name servers (one per line, 2 to 6)', 'rules' => 'nullable|string|max:1000', 'type' => 'text', 'help' => 'Empty = the default name servers of the SKRIME account.'],
                    'accept_terms' => ['label' => 'I accept the SKRIME terms of service for orders placed by the panel and agree that SKRIME starts them immediately (no withdrawal)', 'rules' => 'bool', 'default' => false, 'type' => 'bool', 'help' => 'SKRIME requires this confirmation with every order; without it the panel places no orders.'],
                ],
                'secrets' => [
                    'api_key' => ['label' => 'SKRIME API key', 'rules' => 'string|min:16|max:255|regex:/^[\x21-\x7E]+$/', 'required' => true, 'help' => 'Create the key in the SKRIME dashboard (API). It is stored encrypted and sent only to skrime.eu.'],
                ],
            ],
        ];
    }

    /**
     * @return array{label: string, description: string, config: array<string, array<string, mixed>>, secrets: array<string, array<string, mixed>>}
     */
    public static function get(string $type): array
    {
        $all = self::all();
        if (!isset($all[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown integration type "%s".', $type));
        }

        return $all[$type];
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(static fn (array $type): string => $type['label'], self::all());
    }
}
