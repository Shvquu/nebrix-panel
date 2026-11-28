<?php

declare(strict_types=1);

/*
 * Server self-service: SSH keys, DNS records, reverse DNS, snapshots and
 * resource graphs (see DOCUMENTATION/SERVER_FEATURES.md).
 */
return [
    'server.ssh_keys_max' => ['group' => 'server', 'type' => 'int', 'default' => 10, 'label' => 'SSH keys per customer', 'rules' => 'required|int|min:1|max:50'],
    'server.dns_changes_per_hour' => ['group' => 'server', 'type' => 'int', 'default' => 60, 'label' => 'DNS record changes per domain and hour', 'rules' => 'required|int|min:1|max:1000', 'help' => 'Protects the registrar API limits; staff are not limited.'],
    'server.rdns_changes_per_hour' => ['group' => 'server', 'type' => 'int', 'default' => 20, 'label' => 'Reverse DNS changes per service and hour', 'rules' => 'required|int|min:1|max:500'],
    'server.snapshot_requests_per_hour' => ['group' => 'server', 'type' => 'int', 'default' => 10, 'label' => 'Snapshot requests per server and hour', 'rules' => 'required|int|min:1|max:100'],
    'server.graph_cache_seconds' => ['group' => 'server', 'type' => 'int', 'default' => 60, 'label' => 'Cache resource graphs for … seconds', 'rules' => 'required|int|min:10|max:900', 'help' => 'Graph data is read from Proxmox (rrddata) at most once per period, server and time frame.'],
];
