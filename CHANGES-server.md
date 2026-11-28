# Changes: server self-service (server comfort + DNS)

Details: [DOCUMENTATION/SERVER_FEATURES.md](DOCUMENTATION/SERVER_FEATURES.md).

## User-visible changes

* **DNS records** for domains at SKRIME and INWX whose zone is hosted there (A, AAAA, CNAME, MX, TXT, SRV, CAA; strict validation, TTL 300–86400 at INWX, no CNAME at `@` or next to other records). Switched on per registrar integration ("Customers manage the DNS records of their domains", default off). Applied directly at the registrar, audit-logged, rate-limited per domain. No "reset to defaults" (no API).
* **SSH keys** in the account (Ed25519, ECDSA P-256/384/521, RSA ≥ 2048 bit; parsed, SHA256 fingerprint shown; max. 10 per customer), selectable when ordering a Proxmox server and when reinstalling. KVM: cloud-init `sshkeys` (URL-encoded as Proxmox requires), LXC: `ssh-public-keys`. Only the owner's keys are ever used.
* **Server templates** (Admin → Server templates): OS/app templates per Proxmox integration (name, description, icon, KVM/LXC, VM ID or `vztmpl` volume, optional cloud-init user, SSH keys on/off), assigned to products; customers choose one when ordering and reinstalling. The previous template settings and reinstall list keep working unchanged next to them.
* **Snapshots** for Proxmox KVM/LXC (list, create, roll back with confirmation, delete) through the queue with task status; product limit `max_snapshots`; blocked while a backup, reinstall or restore runs.
* **Resource graphs** (CPU, memory, network, disk I/O; hour/day/week/month) from Proxmox `rrddata`, cached briefly, SVG drawn by `public/assets/js/server-graphs.js` (CSP-safe), for the owner and staff.
* **Reverse DNS self-service** through Hetzner Robot for dedicated servers (main and additional IPv4, single addresses of the IPv6 subnets) and for Proxmox addresses of IP pools linked to a Robot integration (new pool setting).
* **Additional IPv4 addresses** for Proxmox servers: `Nebrix\Integration\Proxmox\ProxmoxIpv4Addon` (AddonProvisioner, kind `ipv4`); shown on the customer and admin service pages; staff add/remove addresses by hand on the admin service page.
* Service pages show a "Server tools" / "DNS" card with links to these pages; the service overview shows the chosen template as "Operating system".

## Migrations

| Migration | Content |
|---|---|
| `2026_11_30_000050_create_ssh_keys` | `nx_ssh_keys` (user, name, type, bits, normalized public key, SHA256 fingerprint unique per user, created/last used) |
| `2026_11_30_000051_create_server_templates` | `nx_server_templates` (integration, name, description, icon, type kvm/lxc, source, cloud-init user, ssh_keys, sort order, active), `nx_server_template_products` (template ↔ product) |
| `2026_11_30_000052_create_server_snapshot_tasks` | `nx_server_snapshot_tasks` (service, action, snapshot, description, status queued/running/done/failed, UPID, error, requester, times) |
| `2026_11_30_000053_add_ip_pool_rdns_and_addon_addresses` | `nx_ip_pools.rdns_integration_id` (FK, Hetzner Robot for reverse DNS); `nx_ip_addresses.addon_key` (unique, `addon:<id>` / `manual:<token>`) and `guest_interface` |

All additive. Service remote state gains `template_id`, `ssh_key_ids` (reinstall choice) and `rdns6` (IPv6 PTR entries set through the panel). Order options of VPS/LXC gain `os_template` and `ssh_keys` (stored only when chosen).

## Settings (group "Server self-service", Admin → Settings → System)

`server.ssh_keys_max` (10), `server.dns_changes_per_hour` (60), `server.rdns_changes_per_hour` (20), `server.snapshot_requests_per_hour` (10), `server.graph_cache_seconds` (60).

Integration settings: SKRIME and INWX `dns_management` (bool, default off). Product setting (VPS/LXC): `max_snapshots` (0–50, also a package key).

## Permissions

No new permissions. Server templates: `products.view` (list) / `products.manage` (changes). Graphs for staff: `services.view`; additional IPv4 by hand: `services.manage`. Pool reverse DNS setting: `integrations.manage`.

## Routes (`config/routes/server.php`)

Customer (auth + verified): `GET/POST /account/ssh-keys`, `POST /account/ssh-keys/{id}/delete`; `GET /services/{id}/dns`, `POST /services/{id}/dns/records`, `…/records/update`, `…/records/delete`; `GET/POST /services/{id}/snapshots`, `POST …/snapshots/rollback`, `…/snapshots/delete`; `GET /services/{id}/graphs`, `GET /services/{id}/graphs.json`; `GET/POST /services/{id}/rdns`, `POST …/rdns/delete`.

Admin: `GET/POST /admin/server-templates`, `GET /admin/server-templates/create`, `GET/POST /admin/server-templates/{id}`, `POST …/{id}/delete`; `GET /admin/services/{id}/graphs(.json)`; `POST /admin/services/{id}/ipv4`, `POST /admin/services/{id}/ipv4/{address}/remove`; `POST /admin/ip-pools/{id}/rdns`.

## Queue jobs / scheduler

Jobs `service.snapshot` (`SnapshotJob`) and `service.ipv4` (`AdditionalIpv4Job`) registered in `JobRegistry`. No scheduler tasks. No mail templates.

## Shared files touched (small, additive edits)

`resources/views/layouts/{base,customer,admin}.twig` (stylesheet `css/server.css`, nav links), `resources/views/customer/store/order.twig` (template/key choices via partial), `resources/views/customer/services/show.twig`, `resources/views/admin/services/show.twig` (include partials), `resources/views/partials/service_actions.twig` (checkbox fields), `resources/views/admin/ip_pools/{form,show}.twig`, `src/Controller/Customer/{ServiceController,StoreController}.php`, `src/Controller/Admin/{ServiceController,SettingsController,IpPoolController}.php` (context variables / settings group / pool setting), `src/Catalog/ProductCategory.php` (VPS/LXC `max_snapshots`, options `os_template`, `ssh_keys`), `src/Billing/OrderService.php` (options with `omit_empty` are not stored when empty), `src/Provisioning/OrderPrechecks.php` (passes `_product_id` to order checks), `src/Provisioning/{ServiceAction,ServiceActionRunner,ServiceManager}.php` (list-valued action fields), `src/Provisioning/{ProxmoxProvisioner,HetznerRobotProvisioner,DomainProvisioner,JobRegistry}.php`, `src/Integration/{IntegrationType,Proxmox/ProxmoxClient,Hetzner/RobotClient,Skrime/SkrimeClient,Inwx/InwxClient}.php`, `src/Network/IpPoolService.php` (main allocation ignores add-on addresses; release clears add-on columns), `src/Security/Validator.php` (rule `ids`).

`DOCUMENTATION/INTEGRATIONS.md` (not edited): the SKRIME section still says "Not supported: DNS zone management" – now supported for zones hosted at SKRIME; INWX gains DNS records; Proxmox token privileges for snapshots (`VM.Snapshot`, `VM.Snapshot.Rollback`) and graphs (`VM.Audit`).

## Tested only against fakes

Proxmox (snapshots, rrddata, `sshkeys`/`ssh-public-keys`, additional interfaces), Hetzner Robot (`/rdns`, server `ip`/`subnet` data), SKRIME (`GET/POST /domain/dns`), INWX (`nameserver.*`). Fakes: `tests/Support/FakeProxmox.php`, `FakeSkrimeDns.php`, `FakeInwxDns.php`, `FakeRobot.php` (plus routes in the tests).

## Limitations / open points

* SKRIME MX/SRV priority encoding in `data` ("10 mail.example.com") and INWX empty `name` for the apex follow the API descriptions; verify in sandbox/OTE.
* No DNS reset/DNSSEC; SKRIME has no per-record TTL.
* Backup restores bring back the guest configuration of the backup time (additional interfaces added later must be re-attached).
* No reverse DNS for IPv4 subnets of dedicated servers.
* The add-on booking/billing (other team) must call `ProxmoxIpv4Addon` from its queued jobs with the add-on's numeric `id`.
