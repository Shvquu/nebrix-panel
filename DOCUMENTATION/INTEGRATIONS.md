# Integrations

Integrations connect Nebrix to the systems that run customers' services. They are managed in **Admin → Integrations** (permission `integrations.manage`); several connections per type are possible (e.g. one Proxmox entry per cluster). Each product is linked to one integration.

> **Test status.** All integrations (Proxmox incl. backups, Pterodactyl incl. backups, Nextcloud, TeamSpeak, Hetzner Robot, Plesk, INWX, SKRIME, EU VIES) are covered by automated tests against **stateful fakes of their APIs** (mocked HTTP, a fake ServerQuery socket server and a fake console WebSocket server). They have **not** been tested against live systems because no credentials or test systems were available. Before going live, run the connection test in the admin panel and walk through order → provisioning → actions → suspension → unsuspension → termination on a staging system for every product type. Use the Hetzner order **test mode**, the INWX **OTE** system and the SKRIME **sandbox** first.

## Common behaviour

| Topic | Behaviour |
|---|---|
| Credentials | Secrets (tokens, passwords) are encrypted with `APP_KEY`, write-only in the UI, never logged. |
| Network safety | URLs/hosts are validated when saved and before every request (HTTPS only unless `INTEGRATIONS_ALLOW_HTTP=true`; no loopback unless `INTEGRATIONS_ALLOW_LOOPBACK=true`; link-local, cloud-metadata, multicast and unspecified addresses always blocked). The resolved IP is pinned for the request, redirects are not followed, timeout `INTEGRATIONS_TIMEOUT` (default 20 s). |
| TLS | Certificates are verified unless "Verify TLS certificate" is switched off for the entry (lab use only). |
| Connection test | "Test connection" in the admin panel and `php bin/nebrix integrations:check`; the scheduler re-checks enabled integrations every 30 minutes and shows the health status. |
| Logging | Every request is logged in `nx_integration_logs` (method, path, status, duration, error) without bodies or secrets; retention configurable. |
| Provisioning | Runs in the queue worker (`service.provision`). Remote IDs and progress are stored **before/after each remote step**, so a retried job continues instead of creating duplicates. Temporary states (remote task still running) are retried with back-off; permanent errors mark the service `failed` and show the error to staff (Admin → Services → *Retry provisioning*). |
| Customer actions | Actions (power, password reset, reinstall, rDNS, name servers, …) are only offered for active services and only if the customer group allows self-service. Only actions the provisioner offers for the service are accepted; input fields are validated server-side (select fields only accept the offered values). Every action is audit-logged. Staff run the same actions under Admin → Services → *Remote actions*. |
| Reinstall | Reinstalling (Proxmox, Hetzner) sets the service to `provisioning` and re-runs the regular, idempotent provisioning job, which first removes the old installation. Limited to 3 per service and hour for customers. |
| Credentials of services | Generated passwords/keys are stored encrypted in `nx_services.credentials`; customers reveal them explicitly (logged in the audit log). |

A product is provisioned automatically when it has an integration and "Provision automatically" is on. Without an integration (any category) the service is created in state `pending`, staff set it up and use Admin → Services → *Activate manually* (optionally storing access data, encrypted).

| Category | Integration |
|---|---|
| VPS/KVM, LXC | Proxmox VE |
| Dedicated server | Hetzner Robot |
| Webhosting | Plesk |
| Domain | INWX or SKRIME (registrar interface for further registrars) |
| Minecraft / game server | Pterodactyl |
| Nextcloud storage | Nextcloud |
| TeamSpeak 3 | TeamSpeak ServerQuery |

## Proxmox VE (VPS/KVM and LXC)

### Connection settings
| Field | Notes |
|---|---|
| API URL | `https://pve.example.com:8006` – one integration per cluster/stand-alone node. |
| API token ID | `user@realm!tokenname` |
| API token secret | UUID shown once by Proxmox when the token is created. |
| Verify TLS certificate | Keep enabled; use a trusted certificate (e.g. ACME) on Proxmox. |
| SSH access to the nodes for LXC root passwords | Optional, one line per node: `node=address` or `node=address:port` (IPv6 with port: `node=[address]:port`). See [LXC root passwords](#lxc-root-passwords). |
| SSH host keys of these nodes | Required with SSH access: the output of `ssh-keyscan -t ed25519 <address>` for every node. The panel connects only to a node presenting one of these keys. |
| SSH user on the nodes | Default `root` (the forced command needs root for `pct`). |
| Private SSH key for LXC root passwords | Leave empty: the panel creates an Ed25519 key when the integration is saved. A pasted key must be unencrypted (OpenSSH or PEM). |

Authentication uses the header `Authorization: PVEAPIToken=<id>=<secret>`. No passwords or tickets are used. SSH is used only for the optional LXC root passwords, with a key that can run nothing but the forced command described below.

### Creating a least-privilege token
Create a dedicated user and token (with privilege separation) and grant only what is needed. A starting point – verify it against your Proxmox version and adjust paths to your pools/storages:

```bash
pveum user add nebrix@pve --comment "Nebrix panel"
```

```bash
pveum role add NebrixProvisioner -privs "VM.Allocate VM.Clone VM.Audit VM.PowerMgmt VM.Config.CPU VM.Config.Memory VM.Config.Disk VM.Config.Network VM.Config.Options VM.Config.Cloudinit VM.Config.HWType Datastore.AllocateSpace Datastore.Audit Sys.Audit SDN.Use"
```

```bash
pveum user token add nebrix@pve panel --privsep 1
```

```bash
pveum acl modify / --tokens 'nebrix@pve!panel' --roles NebrixProvisioner
```

Granting the role on `/` is the simplest option; for tighter scoping grant it on a resource pool (`/pool/<name>`), the target storages (`/storage/<id>`), the nodes (`/nodes`) and the bridge (`/sdn/zones/localnetwork`) instead.

For customer backups additionally `VM.Backup` (create, restore, delete backups) and `Datastore.AllocateSpace` + `Datastore.Audit` on the backup storage. Additional privileges for the console and the password reset: `VM.Console` (`vncproxy`/`vncwebsocket`) and the privilege for QEMU guest agent calls (`VM.Monitor` on Proxmox VE 8; newer versions use `VM.GuestAgent.*` privileges). Check the API viewer of your Proxmox version for `agent/set-user-password` and `vncproxy`.

### Product configuration
**VPS / KVM server** – Node (empty = node with the most free memory), template VM ID, target storage, CPU cores, memory, disk size, bridge, rate limit, first VM ID, IPv4 pool (empty = DHCP), IPv6 pool (empty = SLAAC), additional templates for reinstall (`VM ID|Label` per line). Customers choose a hostname when ordering.

**LXC container** – Node, OS template (`local:vztmpl/debian-12-standard_….tar.zst`), root disk storage, cores, memory, swap, disk, bridge, rate limit, unprivileged flag, first container ID, IPv4 pool, IPv6 pool, additional OS templates for reinstall (`template|Label` per line).

Both: **backup storage** (a Proxmox storage with content type `backup` – directory/NFS/CIFS or Proxmox Backup Server; empty = no customer backups) and **backups per server** (0–50).

### IP pools (static addressing)
Admin → IP pools manages IPv4 networks (CIDR /16–/32) and IPv6 prefixes (/48–/127, not multicast, link-local or IPv4-mapped) with gateway, resolvers and an optional assignable range. Ranges of different pools must not overlap (the same address would go to two customers). IPv4: network, broadcast and gateway addresses are never assigned. IPv6: single addresses of an on-link prefix (typically a /64 on the bridge) are handed out, at most 4096 per pool – from `…::100` by default (the addresses below are usually the router's and the node's own), or from the range you set, e.g. `2001:db8:1::10` to `2001:db8:1::fff`; the gateway may be inside the prefix or link-local (`fe80::1`). Single addresses can be reserved (e.g. for routers). A product with pools gives each service one address per family: KVM via cloud-init (`ipconfig0 ip=…/prefix,gw=…,ip6=…/prefix,gw6=…`, `nameserver`), LXC via `net0 ip=…,gw=…,ip6=…,gw6=…` and `nameserver` (resolvers of both pools). Without an IPv6 pool IPv6 stays on SLAAC (`ip6=auto`); a pool of the wrong family stops provisioning with an error. The allocation is idempotent per service (row lock, unique key), survives reinstalls and is released after the guest has been deleted. When a pool is exhausted provisioning stops with a clear error.

### Lifecycle
| Step | KVM | LXC |
|---|---|---|
| Provision | Full clone of the template → set cores, memory, network (`virtio`, bridge, rate limit), cloud-init user `root` with a generated password, IP via DHCP (`ip=dhcp,ip6=auto`), start on boot → resize disk `scsi0` → start | Create the container from the OS template with a generated root password, `eth0` on the bridge via DHCP, start on boot, `nesting=1` for unprivileged containers → started by Proxmox |
| VMID | `vmid_start + service ID` (deterministic). An existing guest on that VMID is adopted only if its description contains `nebrix-service-<id>` (written at creation); otherwise provisioning stops with a conflict and termination refuses to delete it. Use non-overlapping `vmid_start` ranges per product. | same |
| Suspend / unsuspend | Stop / start the guest | same |
| Terminate | Stop (if running) → delete the guest (`purge`) after the task finished | same |
| Customer actions | Start, shut down, reboot, force stop, **reset root password** (QEMU guest agent; if the agent is not running and the VM has a cloud-init drive, via cloud-init at the next boot – otherwise the action fails without changes), **reinstall**, **console**; live status (state, uptime, CPU, memory, disk, network, IPv4/IPv6) | Start, shut down, reboot, force stop, **reset root password** (only with [SSH access to the node](#lxc-root-passwords); otherwise only by reinstalling), **reinstall**, **console** |
| Reinstall | Stop → delete (ownership checked) → clone the chosen template again with the same VMID and address, new root password | same with the chosen OS template |
| Backups | See [Customer backups](#customer-backups) | same |

Requirements and limitations:
* KVM templates must be **cloud-init enabled** (cloud-init drive attached, `qemu-guest-agent` recommended) and use `scsi0` as the boot disk.
* Without an IP pool, addresses come from **DHCP** on the bridge (provide DHCP or SDN with DHCP).
* Guests imported from RED-Host (`legacy:import --only=services`) have no marker; the importer records their VMID and host name, and the provisioner accepts exactly that VMID only while the guest still has the recorded host name and carries no marker of another service (a reused VMID is never deleted).
* Keep "Unprivileged container" enabled for LXC products: Proxmox allows only `root@pam` to set feature flags on privileged containers, and many privileged-container options are not available to API tokens.
* Multi-cluster: create one integration per cluster and one product per cluster/plan.

### LXC root passwords
Proxmox has no API for changing the password of a container, so the panel can do it over SSH – with a key that may run exactly one program on the node:

1. Edit the integration, enter the SSH address of every node (`pve1=10.0.0.11`) and their host keys (`ssh-keyscan -t ed25519 10.0.0.11`), save. The panel creates its key; the integration page shows the line for `authorized_keys`.
2. On every node install the script shipped as `resources/proxmox/nebrix-lxc-passwd`:

```bash
install -o root -g root -m 0755 nebrix-lxc-passwd /usr/local/sbin/nebrix-lxc-passwd
```

3. Add the line from the integration page to `/root/.ssh/authorized_keys` on every node. It starts with `restrict,command="/usr/local/sbin/nebrix-lxc-passwd"`: whatever the client asks for, SSH runs only the script, without a terminal, forwarding or agent. You may add `from="<panel address>"` in front of `restrict`.

The panel then sends `passwd <container ID> <service ID>` and the new password on standard input (never on a command line). The script accepts nothing else, checks that the container is on this node, that its notes carry the line `nebrix-service-<service ID>` and that it is running, and sets the password with `pct exec <ID> -- chpasswd` (the container needs `chpasswd`, which standard templates have). The container belongs to the customer: it runs with an empty environment, without access to the SSH session's output and for at most 10 seconds; the panel reads at most 64 KiB and 20 seconds. Imported legacy containers (no marker in their notes) do not offer the action. The panel checks the guest's ownership through the API before connecting. Customers see **Reset root password** only for containers on nodes with SSH access. Without this setup LXC passwords change only by reinstalling.

The script was tested with a simulated `pct` and against a local SSH server (forced command, host key pinning, stdin), **not on a Proxmox node**: try it on one container first (`echo NewPassword123x | SSH_ORIGINAL_COMMAND="passwd <ID> <service ID>" /usr/local/sbin/nebrix-lxc-passwd`).

### Customer backups
With a backup storage and a number of backups > 0 on the product, customers get the actions *Create backup*, *Restore backup* and *Delete backup*.

* **Create**: `vzdump` in snapshot mode (`zstd`) to the backup storage with the note `nebrix-service-<id>` (requires Proxmox VE 7.2 or newer for notes). The server keeps running. Only one backup per service runs at a time; the product limit is enforced under a database lock. A finished backup appears in the list.
* **Ownership**: customers only see backups on the product's backup storage that belong to the guest's VMID, carry the note `nebrix-service-<id>` and were made after the service was created. Backups from your own backup jobs (other notes) and backups of an earlier guest with the same VMID are never shown, restored or deleted.
* **Restore**: runs through the job queue like a reinstall (service status `provisioning`, max. 3 per hour): waits for a running backup, checks the backup and the guest's ownership again, stops the guest, restores in place (`force`, same VMID, address and stored access data – the root password is the one valid at backup time) and starts it. If the backup disappeared meanwhile, nothing is changed and the job fails; *Retry provisioning* re-activates the service unchanged.
* **Delete**: removes the backup volume (`DELETE …/storage/<storage>/content/<volume>`).
* **Termination** deletes the service's backups after the guest has been deleted; failures are logged (delete the remaining backups manually) and do not block the termination.
* Customer backups are not a replacement for your own disaster-recovery backups of the cluster.

### Console (noVNC)
Customers (and staff) open a graphical console from the service page. The browser never receives Proxmox credentials:

1. The panel requests a VNC ticket (`POST …/vncproxy websocket=1`) and stores the upstream target (address, path with ticket, API token header) **encrypted** in `nx_console_sessions` under the SHA-256 hash of a random 256-bit token, valid for **60 seconds** and **once**.
2. The page (noVNC 1.7.0, vendored) connects to `CONSOLE_WS_URL?token=…` and authenticates the VNC session with the ticket.
3. `bin/nebrix console:proxy` redeems the token, checks that service and user are still active, connects to Proxmox (address pinned, TLS verified per integration, `Sec-WebSocket-Accept` checked) and relays the WebSocket frames unchanged. It checks the `Origin` (must be `APP_URL`), limits header size, handshake time (10 s), concurrent connections (`CONSOLE_MAX_CONNECTIONS`, default 100), concurrent handshakes (32), connections per client IP (`CONSOLE_MAX_PER_IP`, default 10; the `X-Forwarded-For` address when the peer is listed in `TRUSTED_PROXIES`), open consoles per user (`CONSOLE_MAX_PER_USER`, default 3), session duration (`CONSOLE_MAX_LIFETIME`, default 4 h) and buffered bytes (a direction is paused while the other side has more than 512 KiB queued). The process sets its own memory limit (`CONSOLE_MEMORY_LIMIT`, default 512M).

Run the proxy as a service (docker-compose service `console`, or systemd) and route `CONSOLE_WS_URL` (default `wss://<panel>/console/ws`) from the TLS reverse proxy to it, e.g. nginx:

```nginx
location /console/ws { proxy_pass http://127.0.0.1:8081; proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_read_timeout 4h; access_log off; }
```

`TRUSTED_PROXIES` must contain the reverse proxy's address (as seen by the console proxy – with docker-compose the Docker gateway), otherwise all clients share one address for the per-IP limit. The token is part of the URL; `access_log off` keeps it out of the reverse proxy's logs (it is single-use and expires after 60 seconds either way).

Opening a console is audit-logged and rate-limited (10 per 5 minutes per user).

## Hetzner Robot (dedicated servers)

### Connection settings
| Field | Notes |
|---|---|
| Robot API URL | `https://robot-ws.your-server.de` (default) |
| Webservice user / password | Create a separate webservice user in Robot (Settings → Webservice and app settings). Basic authentication over HTTPS. |
| Pool prefix | Only servers whose Robot **server name** starts with this prefix (default `nebrix-free`) are handed out. Prefixes that overlap the reserved names `nebrix-service-` / `nebrix-wipe-` (e.g. `nebrix-`) are rejected. |
| Allow ordering new servers | Off by default. Products in *order* mode buy servers at Hetzner – this causes costs. |
| Send orders in test mode | On by default: Hetzner validates the order without processing it; provisioning then stops with a clear message. |
| Cancel ordered servers when terminated | Off by default. |
| SSH key fingerprint for orders | Optional key stored in Robot, used for newly ordered servers before the customer installation. |
| Wipe disks of returned servers automatically | Off by default. See *Disk wiping* below. |
| Wipe method | *Full* (default): NVMe format with user-data erase, discard of SSDs, overwrite of HDDs with zeros (can take many hours on large HDDs). *Quick*: removes partition tables and file system signatures and zeroes the first and last 64 MiB of every disk – faster, but data can be recovered with forensic tools. |
| Private SSH key for disk wiping | Optional. Leave empty: on the first wipe the panel creates an Ed25519 key, stores it encrypted and adds its public part to Robot as `nebrix-wipe-<random>`. The key is used only for SSH to rescue systems started by the panel and is never sent to the Robot API; changing the API address does not require it again. |

### Product configuration
Mode (*pool*, *order* or *market*), server model (pool: part of the Robot product name such as `AX41`; order: Robot order product ID such as `AX41-NVMe`), location (`FSN1`, `NBG1`, `HEL1`, empty = any), operating system image (exact name from the Robot Linux installation, e.g. `Debian 12 base`), additional images for reinstall (one per line). The *market* mode (server auction) has its own limits: **maximum monthly price** (net, EUR, required – the product form refuses the mode without it), minimum memory (GB), minimum total disk size (GB) and an optional CPU text (e.g. `Ryzen`); the location applies as a data-center prefix and the image must be offered for the auction server. Set the product price so that it covers the maximum price. For manual fulfilment leave the integration empty and switch off "Provision automatically".

### Lifecycle
| Step | Behaviour |
|---|---|
| Pool assignment | Under a database lock the first ready, not cancelled server with the pool prefix, matching model and location that is not reserved by another service is reserved and renamed to `nebrix-service-<id>`. An empty pool stops provisioning with a clear error (add servers, retry). |
| Order | Only with *Allow ordering*. The order carries the comment `nebrix-service-<id>`; a retry finds an existing order by that comment instead of ordering twice. Waits (10-minute retries) until Hetzner delivers the server. |
| Auction (market) | Only with *Allow ordering* (and test mode off for real purchases). Reads the current auction offers and buys the cheapest one within the product's limits (offers without a numeric monthly price or with a setup fee are never bought) (equal price: higher CPU benchmark first) with the comment `nebrix-service-<id>`; a retry finds that order instead of buying twice. If Hetzner cancels the order (usually sold to someone else meanwhile), the next matching offer is bought – after three cancelled offers provisioning stops with an error. While nothing matches, provisioning waits and checks again every 30 minutes; it never buys a server outside the limits. Auction servers have no setup fee and are billed monthly by Hetzner until they are cancelled – cancel them at Hetzner yourself or enable *Cancel ordered servers when terminated*. The auction offers are compared with their net monthly price (`price`). |
| Pool assignment, firewall | After claiming a server a leftover Robot firewall (from a suspension of the former customer or its own rules) is removed, so the new customer starts with a reachable server. |
| Installation | Deactivates an active rescue system (only one boot configuration can be active), activates the Linux installation (Hetzner generates the root password, stored encrypted), hardware reset, waits until the installer has booted and at least 10 minutes have passed. |
| Suspend / unsuspend | Enables the Robot firewall without rules (incoming traffic dropped) after saving the current firewall (input and output rules); unsuspend restores it. The server keeps running. |
| Terminate | A server that was bought or ordered for the service but is not delivered yet is awaited first (the termination retries until Hetzner delivers or cancels it), so it never stays unclaimed. Boots the rescue system (the customer's OS stops), optionally cancels ordered servers at the earliest date, and renames the server to `nebrix-wipe-<number>`. With *automatic wiping* the server is queued for the disk wipe; otherwise wipe it by hand, remove its firewall configuration and rename it to the pool prefix to return it to the pool. |
| Customer actions | Reboot (CTRL+ALT+DEL), hardware reset, power button, rescue system (password under access data), reverse DNS of the main IPv4, reinstall with an offered image. |
| Status | Model, location, IPv4/IPv6, traffic; cached for 5 minutes because of the Robot API request limits. |

### Disk wiping
With *Wipe disks of returned servers automatically* the scheduler task `hetzner.wipe` (every 5 minutes) takes each terminated server through these steps; the integration page shows the queue (state, attempts, start/end, last error, the last lines of the wipe log) and staff with `integrations.manage` can restart failed wipes or queue servers that already wait under `nebrix-wipe-<number>` (e.g. after switching the option on).

1. **Rescue system**: an active boot configuration is replaced, the rescue system is activated **with the panel's SSH key only** (no password) and the server is reset. The host key Hetzner reports for this rescue system (the key or its MD5/SHA256 fingerprint) is stored and pinned; if Robot reports none, the wipe stops (*failed*, wipe by hand) – the panel never trusts an unpinned host.
2. **Start**: at least 2 minutes later, and only once Robot reports the rescue activation as consumed (the server really booted it – a server that boots its own disk is never trusted), the Robot firewall is cleared (a suspension's firewall would block SSH) and the panel connects as root over SSH (port 22) and starts the wipe script in the background. If the rescue system does not come up within 20 minutes, the server is reset again (3 attempts, then *failed*; the firewall then stays as it was).
3. **Progress**: every run reads the script's state and log. The script stops RAID arrays and volume groups and wipes every disk with the chosen method, checking every step: *full* = NVMe format with user-data erase, or for SSDs a discard that must read back zeros (spot checks at start, middle and end; otherwise overwrite), or an overwrite with zeros of exactly the disk size; disk edges are zeroed in the disk's logical sector size (also 4Kn disks). Any failed step ends the script as *failed*. The state *done* renames the server to `<pool prefix>-<number>` and returns it to the pool. An error of the script, a lost state (rescue system restarted, counts as an attempt), a server that left the Robot account or more than 72 hours end the wipe as *failed* – the server keeps its `nebrix-wipe-` name and is never handed out unwiped. *Retry* starts afresh (new boot, new host key, current method) and is refused while another wipe of the server is open; one server has at most one open wipe, also across integrations.

Before every step the server name is checked: a server renamed by hand is left alone (*taken over by hand*). The scheduler (or its container) needs outbound SSH (TCP 22) to the servers. The wipe script and the SSH connection were tested against a simulated Robot API and a local SSH server, **not on Hetzner hardware**: run the first wipes with a server you do not need and check the log.

Every action, the installation and its reset, and a reinstall request first check live at the Robot API that the server is still named `nebrix-service-<id>`. If test mode is switched off after a test order, the next retry places the real order. Admin → Integrations → *Remote resources* lists all Robot servers with their pool state (free, assigned, wipe, unmanaged).

## Plesk (webhosting)

### Connection settings
Plesk URL (`https://host:8443`), shared IPv4 address for new subscriptions, **API secret key** created on the Plesk server:

```bash
plesk bin secret_key --create -ip-address <panel IP> -description Nebrix
```

The XML API (`/enterprise/control/agent.php`) is used; requests are built with escaped values and responses are parsed without DTDs.

### Product configuration
Name of the Plesk **service plan** (required for automatic provisioning). Customers enter the domain when ordering.

### Lifecycle
* One Plesk customer per Nebrix customer (external ID `nebrix-user-<id>`, login `nx<id>`, generated password), shared by all subscriptions of the customer; one subscription per service with FTP user `nx<service id>` and a generated password.
* A subscription for the domain that belongs to another Plesk customer is never adopted, and a domain can have only one active webhosting service per Plesk server (checked before the order and before provisioning).
* Suspend/unsuspend set the subscription status (16 = suspended by admin / 0 = active); terminate deletes the subscription (the customer account stays).
* Customer actions: **Open Plesk** (one-time login session; the panel shows a link page because the strict CSP does not allow form posts to redirect to other hosts) and **reset the Plesk password** (applies to all subscriptions of the customer).

## INWX (domains)

### Connection settings
Environment (**OTE** test system by default, or live), INWX user name and password, default name servers (one per line), optional two-factor secret (Base32) – the panel then unlocks the session with a generated TOTP code.

### Product configuration
Top-level domain (e.g. `de`); product billing cycle *annually*. Customers enter the domain and, for transfers, the auth code.

### Before the order
* The domain must be a direct second-level domain of the product's TLD.
* The customer profile must contain name, address, postcode, city, country and a phone number (converted to the EPP format `+CC.NUMBER`).
* Availability is checked at INWX; registered domains can only be ordered with an auth code (transfer), free domains only without one.
* A domain that is already managed by another (not terminated) service in the panel cannot be ordered again.

### Lifecycle
* A registrant contact is created from the profile and reused as long as the data does not change; the same contact is used as admin, tech and billing contact.
* Registration (`domain.create`, 1 year, auto-renew) or transfer (`domain.transfer`). The auth code is stored **encrypted** with the order and removed from the service after the registry accepted the transfer request. A rejected request (e.g. wrong auth code) is not counted as submitted; the job fails and the service stays inactive.
* The service becomes active only when INWX reports the domain with status `OK`. Pending registrations are re-checked every 5 minutes, pending transfers every hour; after 2 days (registration) or 10 days (transfer) the job fails with a message to check the domain at the registrar.
* A domain in the INWX account belongs to a service only if the service submitted it and INWX reports the service's registrant contact; other domains are never adopted, changed or deleted.
* Suspend: renewal mode *AUTOEXPIRE*; unsuspend: *AUTORENEW*; terminate: *AUTODELETE* (deleted at the end of the term, never immediately).
* Customer actions: change name servers, show the auth code (stored under access data).

## SKRIME (domains)

Domains through the SKRIME API ([OpenAPI document](https://skrime.eu/docs/openapi.json)). Orders are paid from the **SKRIME account balance**; keep it topped up.

### Connection settings
| Field | Notes |
|---|---|
| Environment | **Sandbox** (default; `https://skrime.eu/sandbox` validates like the live API, changes nothing, costs nothing) or **Live** (`https://skrime.eu/api`). |
| SKRIME API key | Created in the SKRIME dashboard (API). Stored encrypted, sent only as bearer token to `skrime.eu`. Enter it in Admin → Integrations → SKRIME – never in tickets or chats. |
| Default name servers | 2 to 6, one per line. Empty = the default name servers of the SKRIME account. |
| Accept the SKRIME terms | SKRIME requires the acceptance of its terms and of the immediate start (no withdrawal) with every order. The panel sends it only when you tick this box; without it no order is placed and the connection test shows *degraded*. |

TLS is always verified for SKRIME (no option to switch it off).

The connection test signs in (`account/user`) and shows the account and its balance.

### Product configuration
Category *Domain*, integration of type SKRIME, top-level domain (e.g. `de`), billing cycle *annually*. Products can use INWX or SKRIME; the product form lists both.

Instead of entering every ending by hand, use **Admin → Products → Import TLDs**: it reads SKRIME's public price list (`GET /domain/pricelist`, about 1,100 endings, IDN endings as punycode) and creates or re-prices one product per ending with your markup – always with a preview first (details in [ADMIN_GUIDE.md](ADMIN_GUIDE.md#import-tlds-skrime)). Rows with missing or non-numeric prices and invalid endings are ignored.

### Lifecycle
* Before the order (before payment): as for INWX (TLD, complete profile, availability at SKRIME, auth code for registered domains, no duplicate in the panel). Refused as well: **premium domains** (registration or transfer), `.it` domains, addresses without a house number, orders while the terms box is not ticked, and – for customers – orders on a **sandbox** integration (staff can test the sandbox through the shop). `.eu` needs a registrant in the EU or the EEA (checked for every registrar).
* Registration and transfer: `domain/order` with the customer's profile as registrant (first and last name, company, street and house number separately, postcode, city, country, e-mail, phone `+CC.NUMBER`), the name servers and, for transfers, the auth code (stored encrypted, removed after SKRIME accepted the order). SKRIME registers for one year.
* The order answer contains SKRIME's product ID; it is stored with the service and **ownership is tied to it**: a domain belongs to a service only if SKRIME reports this product ID (and the order was placed in the same environment). A refused order (4xx, invalid data) leaves nothing pending. If the answer is lost (timeout), the panel never orders again: it waits for the domain, accepts it only if it was created right after this service's order and its registrant is this customer, and otherwise asks staff to check after 2 days (transfers: 10 days).
* The service becomes active when SKRIME reports the domain product as `active` (transfers: waits up to 10 days).
* **Renewal**: SKRIME's own auto renewal stays off. The scheduler task `domains.renew` (hourly) renews a domain (`product/renew`) while its expiry date at SKRIME lies before the date the customer has paid for (`next_due_at`) – so only paid periods are renewed, from the SKRIME balance. At most one renewal is requested per expiry date (a lost answer is never repeated; check such cases in the SKRIME interface); refused renewals (e.g. insufficient balance) are retried every 6 hours. Suspended or terminated services are not renewed, so the domain ends with its term.
* Suspend, unsuspend and terminate keep SKRIME's auto renewal off (never an immediate deletion).
* Customer actions: change name servers; show the auth code (fetched from SKRIME only on this request, stored under access data).
* A product that SKRIME blocks (e.g. overdue payment) answers with HTTP 423; the panel shows SKRIME's message and changes nothing.
* Not supported: `.it` domains (SKRIME needs a tax or ID number as `regCode`, which the panel does not collect), DNS zone management and contact changes after the registration (do them in the SKRIME interface).

**Test status.** The client was tested against a stateful fake of the documented API; the public endpoints `domain/check` and `domain/pricelist` (shape used by the TLD import) and the error answer for an invalid key were checked against the live API. Orders were **not** placed for real, and the sandbox could not be reached from the development environment (Cloudflare check). Before going live: run the connection test with your key, place a test order in the **sandbox**, then register one cheap domain live and check it in the SKRIME interface.

## Pterodactyl (Minecraft / game servers)

### Connection settings
| Field | Notes |
|---|---|
| Panel URL | `https://games.example.com` |
| Application API key (`ptla_…`) | Admin → Application API in Pterodactyl. Permissions: Users – read & write, Servers – read & write, Nests and Eggs – read; everything else – none. |
| Client API key (`ptlc_…`, optional) | Client API key of a root administrator; enables live status, power actions and backups in the customer panel. Treat it like a root credential. |

### Product configuration
Nest ID, egg ID, location ID, memory, swap, disk, CPU limit, block IO weight, databases, additional ports, backups, Docker image (empty = egg default), egg variable overrides (JSON). Customers choose a server name.

### Lifecycle
* One Pterodactyl user per customer (`external_id` = `nebrix-user-<id>`, user name `nx<id>`), looked up by external ID; created on the first order with a generated password and re-used for further servers (the access data of the first service is shown again). If an account with the customer's e-mail already exists in Pterodactyl but was not created by Nebrix, user creation fails with a validation error and the service is marked `failed` – set the account's external ID to `nebrix-user-<id>` in Pterodactyl (or change its e-mail) and retry provisioning.
* One server per service (`external_id` = `nebrix-service-<id>`), deployed automatically to a node of the configured location (Pterodactyl deploy API picks a free allocation). A retried job finds the existing server by external ID.
* Suspend/unsuspend use the Application API suspend endpoints; termination deletes the server (the panel user is kept).
* Customer actions (with client API key): start, restart, stop, kill; live resource usage.
* **Backups** (with client API key and a backup limit > 0 on the product): create, restore (all current files are deleted first – `truncate`), download (short-lived signed URL of the node) and delete finished backups of the customer's own server. The limit is the product's "Backups" value, which Pterodactyl also enforces (`feature_limits.backups`). Deleting a server in Pterodactyl deletes its backups.

## Nextcloud (cloud storage)

### Connection settings
Nextcloud URL, admin user name, admin **app password** (Settings → Security in Nextcloud), user ID prefix (default `nx`).

### Product configuration
Storage quota (GB), optional Nextcloud group.

### Lifecycle
* One account per service: user ID `<prefix><service-id>`, generated password, display name and e-mail of the customer, quota and optional group, created via the Provisioning API (`/ocs/v2.php/cloud/users`). The password is stored before the remote call, so a retry can adopt the account.
* Suspend disables the account (data kept), unsuspend enables it, terminate deletes the account **and its data**.
* Customer action: generate a new password.

## TeamSpeak 3

### Connection settings
ServerQuery host, connection type and port, query login (default `serveradmin`), query password, public host name shown to customers, voice port range for new servers.

| Connection | Notes |
|---|---|
| **SSH** (recommended) | Encrypted ServerQuery over SSH, TeamSpeak 3 server 3.3 or newer (and TeamSpeak 6), default port **10022**. Enable it on the TeamSpeak server (TeamSpeak 3: start parameter / ini `query_protocols=raw,ssh`, port `query_ssh_port=10022`). The SSH login uses the ServerQuery login and password. Enter the server's SSH host key (`ssh-keyscan -p 10022 <host>`); the panel connects only to a server presenting that key – without a key, SSH connections are refused. |
| Raw TCP | Port 10011, **not encrypted**. Allow the query port only from the panel's IP address and connect over a private network or VPN. |

Changing the connection type asks for the query password again. Add the panel IP to `query_ip_allowlist.txt` on the TeamSpeak server so it is not flood-banned.

The SSH transport was tested against a real SSH server running a simulated ServerQuery (password login, host key pinning, commands and errors), not against a TeamSpeak server; run the connection test after switching.

### Product configuration
Slots. Customers choose a server name.

### Lifecycle
* Provision: reserves the first voice port in the configured range that is neither used on the server nor reserved by another service of the integration (under a database lock), stores it **before** `servercreate`, creates the virtual server (name, slots, port, autostart on) and stores the returned server admin privilege key encrypted. A retried job adopts a virtual server on the reserved port only if it has the requested name; any other server on that port is reported as a conflict.
* Suspend: disable autostart and stop the virtual server; unsuspend: enable autostart and start it.
* Terminate: stop and delete the virtual server.
* Customer actions: start, stop, restart, create a new admin privilege key.
* All parameter values are escaped according to the ServerQuery specification; command and parameter names are fixed in code.

## Imported RED-Host services

`legacy:import --only=services` links legacy LXC/KVM servers, TeamSpeak servers, game servers and webspaces to the integrations you map (see UPGRADE_GUIDE.md). These services are marked `legacy`: the provisioners accept the remote IDs recorded by the legacy database (Proxmox VMID, Pterodactyl server ID, Plesk subscription ID) although the objects carry no Nebrix marker – with additional checks before anything is deleted: a Proxmox guest must still have the recorded host name and no marker of another service, a Pterodactyl server the recorded identifier.

## Discord (account link and roles)

Customers link their Discord account on the dashboard; optionally they are added to your community server and get roles automatically.

**Set-up**
1. Discord developer portal → *New Application*. Under **OAuth2** copy the *Client ID* and *Client Secret* and add the redirect URI shown in Admin → Settings → System (`<APP_URL>/account/discord/callback`).
2. For server join and roles: **Bot** → create a bot token; invite the bot to your server with the permissions *Create Invite* and *Manage Roles* (OAuth2 URL generator, scope `bot`). In the server's role list, drag the bot's role **above** the roles it should assign.
3. Enable *Developer Mode* in Discord (User Settings → Advanced) and copy the server ID and the role IDs (right click → *Copy ID*).
4. Admin → Settings → System → **Discord**: enable linking, enter client ID and secret, bot token, server ID, the role for linked customers and/or the role for customers with an active service, and the invite link shown on the dashboard.

**Behaviour**
* Linking uses the OAuth2 authorization code flow with the scope `identify` (plus `guilds.join` when customers are added to the server). The `state` is random, bound to the customer's session, single-use and valid for 10 minutes. The user's access token is used once – to read the Discord ID and to add the user to the server – and is never stored.
* A Discord account can be linked to one customer account only. Customers can unlink at any time; staff can unlink on the customer page. Closing a customer account removes the link.
* Roles are applied by the queue worker (`discord.sync`; Discord rate limits are retried) right after linking and re-checked by the scheduler every 30 minutes (`discord.sync_roles`): "linked" while the account is linked and active, "active customer" while at least one service is active. The panel only removes roles it granted itself – roles your team gives by hand stay untouched.
* Stored: Discord user ID, user name, display name, link time, the roles the panel granted and the last sync status. Mention the Discord link in your privacy policy.
* Not tested against the live Discord API in this environment (no application credentials); the automated tests use a fake Discord API.

## EU VIES (VAT ID check)

VAT IDs are verified with the public REST interface of the European Commission (`https://ec.europa.eu/taxation_customs/vies/rest-api/ms/<country>/vat/<number>`, no credentials). Only country code and number are sent. Results: valid, invalid, or unknown when VIES or the member state's service is unavailable (a newly entered VAT ID stays unchecked and VAT is charged; an earlier result for an unchanged VAT ID is kept; the scheduler retries). Reverse charge also needs the customer's company name to match the holder name VIES reports; several member states (e.g. Germany, Spain) do not disclose names – then the VAT ID alone counts. The service is subject to the EU's availability and fair-use rules.

## E-invoices (XRechnung)

Invoice XML is generated as XRechnung 3.0 in the UN/CEFACT CII syntax (`urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0`) with VAT categories S, AE, E and O and type codes 380 (invoice) and 381 (correction). Every generated file is checked against the CII XSD in the automated tests. The official Schematron rules (EN 16931 + XRechnung) were **not** executed in this environment; validate sample invoices with the KoSIT validator before sending e-invoices:

```bash
java -jar validationtool-<version>-standalone.jar -s <configuration>/scenarios.xml -r <configuration> invoice.xml
```

(Validator: `github.com/itplr-kosit/validator`, configuration for XRechnung 3.0: `github.com/itplr-kosit/validator-configuration-xrechnung` – check their READMEs for the exact options of the current release.) Download an XML with Admin → Invoices → *E-invoice (XRechnung)*. Required seller data (address, VAT ID or tax number, contact person, e-mail and phone) is configured in Settings → General → Invoice details; the admin dashboard warns while it is incomplete.

## Adding a new integration type

1. Add the type with its connection fields to `src/Integration/IntegrationType.php`.
2. Implement a client (extend the pattern in `src/Integration/Http/ApiClient.php`, which applies the URL guard, timeouts and logging) and register its health check in `IntegrationManager`.
3. Add product category fields in `src/Catalog/ProductCategory.php` (all values validated with rules).
4. Implement `Nebrix\Provisioning\Provisioner` (provision, suspend, unsuspend, terminate, status, actions, perform) with persisted progress for idempotency, and register it in `ProvisionerRegistry`.
5. Add tests with mocked responses (see `tests/Integration/Integrations/`).
