# Design: authenticated-read-parity

## Approach
Inject `IUserSession` into the assembler(s) that apply the anonymous strip
list; branch once per request (not per object) on `getUser() !== null`.

## Decisions

### D1 — Session check, not permission re-derivation
OR RBAC already decided the caller may read the object (`_rbac: true` on the
delegated search). Re-deriving per-property permissions in the leaf would
duplicate OR's authority (ADR-022). All-or-nothing on session is the whole
design.

### D2 — Single strip-list source
The `$unwantedProperties` list is extracted to one private constant reused by
every public-read assembler that strips (`CatalogiService`; plus
`PublicationService`/`PublicationQueryService` if apply confirms they carry a
duplicate list), so the anonymous envelope cannot fork between endpoints.

### D3 — Byte-parity guard for anonymous
A golden-fixture PHPUnit test freezes the anonymous envelope. This is a
public, CORS-consumed API: the anonymous shape is a compatibility contract
(portal frontends, harvester consumers), so the test exists to prove this
change ships zero anonymous drift.

## Security note
This widens metadata exposure ONLY to sessions OR RBAC already trusts to read
the object; `validation`/`retention`/`owner` are not secrets to a caller who
can already open the object in OpenRegister. Gate `security-change-has-tests`
applies (session-dependent response shaping) — tests ship in the same PR.
