# Design: integration-feed-consumer-credentials

Read at opencatalogi development `9aa54150` and Nextcloud server public API (`lib/public`).

## Where it lands

| piece | file | what is there now |
|---|---|---|
| gate | `lib/Controller/OoapiController.php:195` `requireAuthenticatedConsumer()`: a signed-in Nextcloud user, then `OoapiService::isConsumerAllowed($uid)` | account-based only |
| allowlist | `lib/Service/OoapiService.php:168` `isConsumerAllowed()` reads app config `ooapi_consumers` (CSV of user ids, empty means every user) | instance-wide |
| setting | `lib/Service/SettingsService.php:505-516` default and comment, `:699` allowed key | no screen reads it (grep `src/`) |
| OOAPI schemas | `lib/Settings/register.d/ooapi-catalog-publication.json` (`course`, `program`, `offering`), `authorization.read: ["authenticated"]` (:95-99) | any signed-in user |
| catalogue | schema `catalog` in `lib/Settings/publication_register.json`: `hasOoapi`, `registers`, `schemas`, ... | no consumer field |
| Nextcloud | `OCP\IUserSession::setVolatileActiveUser()`, `OCP\Security\IHasher::hash()` and `verify()`, `OCP\IUserManager`, `OCP\IGroupManager` | public API; app passwords have no public create API (`occ user:auth-tokens:add` uses the private token provider) |

## D1. A consumer is an account the app manages, with keys the app issues

A new schema `feedConsumer` in `lib/Settings/register.d/feed-consumer-credentials.json` (publication register): `name`, `contact`, `catalog` ($ref catalog), `account` (the Nextcloud user id), `enabled`, and `keys[]`, each with `id`, `label`, `hash`, `createdAt`, `expiresAt`, `lastUsedAt`, `revokedAt`. The `hash` is `IHasher::hash()` of the secret part, a verifier and not the secret, so ADR-064's rule that no object holds a secret is kept: the secret exists only in the one response that shows it.

Adding a consumer creates a Nextcloud account `feed-<slug>` with a random password nobody sees, in the group `opencatalogi-feed-<catalogSlug>`. The account exists so OpenRegister's schema authorization (OOAPI-008) decides reads, as it does today.

## D2. The key carries its own lookup

A key reads `oc_<consumerId>.<keyId>.<secret>`. The gate loads one `feedConsumer` by id with RBAC off (a system read, the object holds no secret), finds the key by id, checks it is not revoked or expired, and verifies the secret with `IHasher::verify()`. No scan over all consumers. On success it sets the consumer's account as the volatile active user for this request (`setVolatileActiveUser()`), writes `lastUsedAt` at most once an hour, and continues as today. A wrong key answers 401 and counts against the anonymous rate limit.

The key is sent as `Authorization: Bearer <key>`. Basic auth with an account and app password keeps working as before.

## D3. Scoped to one catalogue

The OOAPI actions check that the consumer's `catalog` is the requested catalogue before any read (403 otherwise). The OOAPI schemas' read rule becomes: members of the catalogue's consumer group, or users on the existing instance-wide list. For catalogues whose schemas are not public, the same key reads the authenticated publication API of that catalogue (`authenticated-read-parity`), under the same RBAC.

## D4. The screen

A "Feed consumers" section on the catalogue page (`CatalogDetailPage.vue`, admin only): list consumers with their keys, last use and expiry; Add consumer; Generate key (shown once with a copy button and a warning that it cannot be shown again); Revoke. The section also shows the instance-wide `ooapi_consumers` list read-only, with where to change it.

## Declarative or imperative

- `feedConsumer` and its fields are declared schema.
- Key verification and account creation are imperative: they touch authentication (ADR-031 exception, security-critical guard).
- Endpoints: `POST /api/catalogs/{slug}/consumers`, `POST .../consumers/{id}/keys`, `POST .../keys/{keyId}/revoke`, all `#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]`.

## Seed data

None: a consumer is a real account and a real key, and seeding one would ship a known key.

## Risks

- A leaked key reads one catalogue's protected feeds until revoked. Keys can carry an expiry, and `lastUsedAt` shows use.
- Accounts created for consumers appear in Nextcloud's user list. They are named `feed-...`, placed in one group per catalogue, and deleting the consumer disables the account.
