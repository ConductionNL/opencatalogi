# ADR-002: Directory Discovery — RBAC Bypass and Multitenancy Boundary

## Status

Accepted

## Date

2026-05-28

## Context

`DirectoryService::getUniqueDirectories()` searches listing objects in OpenRegister to
build the set of remote publication URLs used for federation.  The search call carried
two flags:

- `_rbac: false` — skip object-level read-permission checks
- `_multitenancy: false` — bypass the per-tenant scope, mixing every tenant's listings

The `_rbac: false` flag is correct and intentional: listing objects are public-by-design
(their `authorization.read` contains `"public"`), so asking the RBAC layer to re-evaluate
them would produce redundant, context-dependent results for unauthenticated callers.

The `_multitenancy: false` flag is **not** appropriate here.  Cross-tenant mixing means
that any tenant's malformed or hostile listing URL could appear in every other tenant's
federation discovery set.  It also means a single-tenant OpenCatalogi instance silently
sees data from sibling tenants, which violates isolation expectations.

Cross-instance federation — fetching publications from *other* OpenCatalogi deployments —
is already handled through the URL-based remote-fetch path (`syncListing`,
`fetchRemoteListings`), which validates each outbound URL with `assertSafeOutboundUrl()`
before making a network request.  No cross-tenant listing mixing is necessary for that
path to work.

## Decision

1. **Keep `_rbac: false`** on `getUniqueDirectories()`.  Listing objects are public by
   design; bypassing RBAC for this read is correct.
2. **Remove `_multitenancy: false`** from `getUniqueDirectories()`.  Each tenant queries
   only its own listing objects.  The tenant boundary is enforced by OpenRegister's
   default multitenancy scope.
3. **Cross-instance federation continues through the explicit URL-based remote-fetch
   path** (`syncListing` / `fetchRemoteListings`).  URLs stored in listing objects point
   to *remote* OpenCatalogi instances, not to other tenants on the same host.

## Consequences

- Tenants on a shared OpenCatalogi host are isolated: no tenant can inject a listing URL
  into another tenant's federation set.
- The public-discovery use-case is unaffected: within a single tenant, all listing objects
  are returned regardless of the calling user's identity.
- The remote-fetch SSRF guard (`assertSafeOutboundUrl`) remains the primary defence
  against malicious URLs; the multitenancy scope is now an additional layer.
