---
kind: code
---

## Why

To federate with another OpenCatalogi today, an admin or a peer must already know the full directory URL, for example `https://cloud.example.org/index.php/apps/opencatalogi/api/directory`. Typing only the host fails URL validation. The only way to find a new peer is the hard-coded national directory.

The OpenRegister AppHost now publishes every app's `discovery` manifest block to anonymous callers of `/ocs/v2.php/cloud/capabilities` (openregister change `apphost-discovery-manifest`). OpenCatalogi declares its directory there as `links.directory`. A hostname is therefore enough to find a peer's directory, in the same way any other Nextcloud client learns what a server offers.

## What Changes

- **`DirectoryService::resolveDirectoryUrl()` turns a bare host into a directory URL.** A bare host is something like `cloud.example.org`, optionally with a scheme or port.
  - It reads the peer's public capabilities and takes `opencatalogi.discovery.links.directory`.
  - If the peer runs without pretty URLs (`core.mod-rewrite-working` false), it adds the `/index.php` prefix.
  - When the capability is missing or unreadable, it falls back to the conventional path `/index.php/apps/opencatalogi/api/directory`.
- **Full URLs are untouched.** A URL with a path is returned unchanged, so every stored listing and every peer broadcast behaves byte for byte as before.
- **Not every string is resolved.** Only input that names a host (a dotted name, an IP literal or an explicit port) is resolved. Anything else still fails URL validation with the same message as before.
- **`syncDirectory()` calls the resolver first.** The admin "Add directory" dialog (`/api/listings/add`), the public broadcast-receive endpoint (`POST /api/directory`) and cron sync all accept a hostname.
- **Same SSRF protection as directory sync.** The capabilities fetch goes through `assertSafeOutboundUrl()` and `safeGet()`, which validates every redirect hop. `safeGet()` gains an optional `headers` argument for `OCS-APIRequest`. An advertised path that is protocol-relative (`//host`) or contains `..` is ignored, so a peer cannot redirect us to another host.
- **OpenCatalogi declares its own `discovery` block:**
  - `links.directory` and `links.openapi`;
  - DCAT-AP-NL, OOAPI, schema.org, DiWoo and the OpenCatalogi federation protocol, plus the other standards it actually serves.

## Impact

- **Backwards compatible.** Existing URLs, listings and peers are unchanged.
- **Peer support.** Peers that publish the discovery capability are found exactly. Older peers are found through the conventional path.
- **Dependencies.** The `discovery` manifest block needs `@conduction/nextcloud-vue` schema 2.44.0. Publication needs the OpenRegister AppHost discovery change.
