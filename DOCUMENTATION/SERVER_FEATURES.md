# Server self-service

Customer self-service for servers and domains: DNS records, SSH keys, reverse DNS, snapshots, resource graphs, server templates and additional IPv4 addresses. Settings are in **Admin → Settings → System → Server self-service**.

> **Test status.** Every remote call of these features was tested against **fakes of the APIs only** (Proxmox VE, Hetzner Robot, SKRIME, INWX – mocked HTTP in `tests/Support`). Nothing was run against real servers or registrars. Try each feature on a staging system (Proxmox test node, a spare Robot server, the SKRIME sandbox / INWX OTE) before offering it to customers.

## DNS records of domains

Customers manage the DNS records of their domains on the domain's service page (**Manage DNS records**, `/services/{id}/dns`) when

* the domain was registered through **SKRIME** or **INWX**,
* the registrar integration has **"Customers manage the DNS records of their domains"** switched on (Admin → Integrations → edit; off by default), and
* the registrar hosts a zone for the domain (SKRIME: `GET /domain/dns`, INWX: `nameserver.info`). A domain whose zone is elsewhere shows a notice instead.

Record types: **A, AAAA, CNAME, MX, TXT, SRV, CAA**. Other records of the zone (NS, SOA, TLSA, …) are shown read-only and never changed. Validation:

| Type | Rule |
|---|---|
| Name | `@` for the domain, otherwise labels of letters, digits, `-`, `_` (≤ 63 characters each, `*` only as the leftmost label); a fully qualified name of the domain is shortened; total ≤ 253 characters |
| A / AAAA | an IPv4 / IPv6 address (stored in canonical form) |
| CNAME | a host name (no IP), not at `@`, not next to other records of the same name, not pointing to itself |
| MX | priority 0–65535 and a host name (no IP) |
| TXT | printable ASCII, at most 1024 characters (one pair of surrounding quotes is removed) |
| SRV | name `_service._proto[.sub]`, priority 0–65535, value `weight port target` (0–65535 each) |
| CAA | `flags tag "value"` with flags 0/128, tags `issue`, `issuewild` (a CA domain or `;`) or `iodef` (`mailto:` or `https://`) |
| TTL | INWX: 300–86400 seconds (default 3600). SKRIME records have no TTL of their own (the field is hidden). |

Duplicates are refused; a zone holds at most 100 records. There is **no "reset to defaults"**: neither API offers one.

**How changes are applied.** Directly at the registrar within the request (no queue), after the ownership check of the domain (the same check as name server changes: SKRIME product ID / INWX registrant handle) and under a per-domain database lock:

* **SKRIME** replaces the whole zone with every `POST /domain/dns`: the panel reads the zone, changes the one record and sends all records back – including the records it does not edit. SKRIME has no record IDs; the panel derives an ID from the record's content, so a record changed elsewhere in the meantime answers "does not exist (anymore)". MX and SRV priorities are sent as the first field of `data` (`"10 mail.example.com"`, `"10 5 5060 sip.example.com"`) – the API description shows only `{name, type, data}`; verify the format with the sandbox before going live.
* **INWX** uses `nameserver.createRecord` (relative name, empty for `@`), `nameserver.updateRecord` and `nameserver.deleteRecord` by record ID. Record IDs are only accepted when they belong to the current zone of the customer's own domain (never another domain of the reseller account).

Errors of the registrar are logged and the customer sees a generic message. Every change is written to the audit log (`domain.dns_record_created`, `…_updated` with the previous value, `…_deleted`). Changes are limited per domain and hour (setting **DNS record changes per domain and hour**, default 60). Customer groups without "manage services" can view but not change records.

## SSH keys

**Account → SSH keys** (`/account/ssh-keys`): customers store public keys (name + key). A key is parsed in the SSH wire format, not matched with a pattern:

* accepted: `ssh-ed25519`, `ecdsa-sha2-nistp256/384/521` (uncompressed points), `ssh-rsa` with **at least 2048 bits** (and at most 16384);
* refused: other types (DSA, security keys), `authorized_keys` options (`command="…"`), several keys, private keys, a blob whose type differs from the declared type, truncated or trailing data;
* the **SHA256 fingerprint** (as printed by `ssh-keygen -l`) is shown; the comment is reduced to printable characters (≤ 100);
* at most **10 keys per customer** (setting **SSH keys per customer**, 1–50), counted under a lock of the customer row; the same key once per account; adding is limited to 20 per hour.

Keys are chosen **when ordering** a Proxmox server (VPS/LXC) and **when reinstalling** (reinstall form, also for staff). Only keys of the **service owner** are ever installed: the order check and the reinstall form accept only the owner's key IDs, and the provisioning job resolves the stored IDs against the owner again (an ID of another customer in the stored options is ignored).

* **KVM**: cloud-init `sshkeys`. Proxmox expects this value **URL-encoded** (spaces as `%20`, not `+`); the panel encodes it with `rawurlencode()` and the form encoding of the API request encodes it again, as Proxmox requires.
* **LXC**: `ssh-public-keys` (newline-separated `authorized_keys` lines) when the container is created or re-created.

Templates can switch off SSH keys (e.g. images without SSH). Deleting a key in the account does not remove it from servers.

## Server templates

**Admin → Server templates** (`/admin/server-templates`, view `products.view`, changes `products.manage`, audit-logged): templates per Proxmox integration with name, description, icon, type (KVM/LXC), the Proxmox source (KVM: VM ID of a cloud-init template; LXC: `storage:vztmpl/…` volume), an optional **default cloud-init user** (KVM; e.g. `debian` – then the login and the password reset use this user instead of `root`), whether SSH keys are installed, sort order and an active flag. The template form lists the products that offer it (only VPS/LXC products of the same integration and type are accepted).

* **Order**: the order page of a product that offers templates shows them as choices (option `os_template`; the default is the product's own image). The order check refuses templates the product does not offer.
* **Reinstall**: the reinstall form offers the previous list unchanged – the product's template ("Default image") and the lines of "Additional templates for reinstall" – followed by the product's server templates (`t:<id>`). Nothing was migrated: both models work side by side.
* The chosen template's source is stored with the service (a later change of the template does not change installed servers); the service page shows its name as "Operating system". Deleting a template does not affect installed servers.

## Snapshots (Proxmox KVM/LXC)

With **Snapshots per server** (`max_snapshots`, 0–50, product setting; also applied by package changes) customers get **Snapshots** on the service page (`/services/{id}/snapshots`): list (name, description, time), **create** (name: a letter followed by letters, digits, `-`, `_`, 2–40 characters; description ≤ 200), **roll back** (confirmation checkbox and dialog – the current state is overwritten) and **delete**.

* Every request is recorded in `nx_server_snapshot_tasks` and executed by the queue worker (job `service.snapshot`); the page shows the latest tasks with their state (queued, running, done, failed).
* One task per server at a time; no snapshot tasks while the server is set up, reinstalled or restored (status not active) or while a backup runs (the job waits for a backup that started after the request); reinstalls and restores are refused while a snapshot task is open.
* The limit is checked under a per-server lock; KVM snapshots save the disks only (`vmstate=0`); a rollback starts the guest again when it was running.
* Every remote call checks the guest's ownership marker. Requests are audit-logged (`service.snapshot_*_requested`) and limited per server and hour (setting, default 10).
* The guest's storage must support snapshots (ZFS, LVM-thin, Ceph, qcow2); otherwise the task fails with Proxmox's message.

Required Proxmox privilege: `VM.Snapshot` (and `VM.Snapshot.Rollback` on Proxmox VE 8+).

## Resource graphs (Proxmox)

**Resource graphs** (`/services/{id}/graphs`, staff: `/admin/services/{id}/graphs`) show CPU, memory (used/total), network (in/out) and disk I/O (read/write) for the last hour, day, week or month from Proxmox `rrddata` (`cf=AVERAGE`). The data comes from a JSON endpoint (`…/graphs.json?timeframe=…`, owner or staff with `services.view` only, 120 requests per 5 minutes per customer) and is cached per server and time frame (setting **Cache resource graphs for … seconds**, default 60, stored encrypted in `nx_cache`). The charts are drawn by `public/assets/js/server-graphs.js` as SVG (no external libraries, no inline styles or scripts – CSP-safe). Required privilege: `VM.Audit`.

## Reverse DNS

**Reverse DNS** (`/services/{id}/rdns`) lets customers set or remove the PTR record of their addresses through the **Hetzner Robot** API (`GET/POST/DELETE /rdns/{ip}`):

* **Dedicated servers** of a Hetzner Robot integration: the main IPv4, the additional single IPv4 addresses of the server and any single IPv6 address inside the server's IPv6 subnets (server data read live after checking the server name `nebrix-service-<id>`). IPv6 addresses set through the panel are listed afterwards.
* **Proxmox servers** whose addresses come from an IP pool that is linked to a Hetzner Robot integration: new pool setting **Reverse DNS through Hetzner Robot** (Admin → IP pools → pool, `integrations.manage`). Pools without it have no reverse DNS; the page is hidden.

Only addresses that belong to the service are accepted; host names must be fully qualified (lower-cased, trailing dot removed, labels ≤ 63, total ≤ 253). Changes are audit-logged (`service.rdns_updated`, `service.rdns_deleted`) and limited per service and hour (setting, default 20). Current PTR values are cached for 10 minutes (Robot request limits). The previous action "Set reverse DNS" of dedicated servers (main IPv4) still exists.

## Additional IPv4 addresses

`Nebrix\Integration\Proxmox\ProxmoxIpv4Addon` implements `Nebrix\Service\Addon\AddonProvisioner` for the add-on kind **`ipv4`** (the booking and billing of add-ons is a separate feature):

* `supports('ipv4', $service)`: VPS/LXC services with an integration whose product has an IPv4 pool (`ip_pool_id`).
* `provision($service, $addon)`: allocates an address from the service's pool in a transaction with the service row locked; idempotent per add-on (`nx_ip_addresses.addon_key = addon:<id>`, unique), so a retried job returns the same address. The address gets the lowest free guest interface (`net1` …) – interfaces already present on the guest are skipped – which is stored before the remote change. Returns `['address' => …, 'gateway' => …, 'netmask' => …]` (gateway and netmask of the pool).
  * **LXC**: `netN = name=ethN,bridge=<bridge>,ip=<address>/<prefix>[,rate=…]` (applied at once).
  * **KVM**: `netN = virtio,bridge=<bridge>[,rate=…]` plus cloud-init `ipconfigN = ip=<address>/<prefix>`; the NIC is hot-plugged, the guest configures the address at its next start (cloud-init).
  * Without a guest yet (provisioning pending) the address is allocated and attached when the guest is created; reinstalls re-create the interfaces.
* `deprovision($service, $addon)`: removes the interface (only when it still carries this address; otherwise it is left alone and a warning is logged) and returns the address to the pool; repeated calls do nothing.
* Termination of the service releases all its addresses (main and additional).

Staff add or remove an address by hand on the admin service page (**Server tools → Add IPv4 address**, `services.manage`): the work runs through the queue (job `service.ipv4`, visible in the service's job list) and is audit-logged; the price is not changed. Addresses of booked add-ons cannot be removed there. Customers see their additional addresses (address, netmask, gateway, interface) on the service page.

The main address of a service is allocated as before; an allocation ignores add-on addresses (they carry an add-on key).

## Settings

| Key | Default | Meaning |
|---|---|---|
| `server.ssh_keys_max` | 10 | SSH keys per customer (1–50) |
| `server.dns_changes_per_hour` | 60 | DNS record changes per domain and hour |
| `server.rdns_changes_per_hour` | 20 | Reverse DNS changes per service and hour |
| `server.snapshot_requests_per_hour` | 10 | Snapshot requests per server and hour |
| `server.graph_cache_seconds` | 60 | Cache time of graph data (10–900) |

## Limitations

* Tested against API fakes only (see above). The SKRIME record format for MX/SRV priorities and the INWX handling of empty names for `@` follow the published API descriptions and common client libraries; check them in the SKRIME sandbox / INWX OTE.
* No "reset to defaults" for DNS zones (no API for it); no DNSSEC management.
* Snapshots are listed as Proxmox reports them for the guest (snapshots made by staff in Proxmox are included and count towards the limit).
* Restoring a backup restores the guest configuration of the backup time: additional interfaces added later are missing until the next reinstall or a manual re-attach.
* Reverse DNS for IPv4 subnets of dedicated servers (Robot `subnet` entries of IPv4) is not offered; only the server's single addresses and its IPv6 subnets.
