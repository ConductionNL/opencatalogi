# Tasks: integration-feed-consumer-credentials

## 1. Schema and accounts

- [x] 1.1 Add `feedConsumer` in `lib/Settings/register.d/feed-consumer-credentials.json` (REQ-FCC-001). Verify: clean `occ app:enable` imports it (needs a live instance; not run); the schema passes the existing register fragment tests (`WooJourneyRegisterTest`).
- [x] 1.2 Create and disable consumer accounts in the catalogue's consumer group (REQ-FCC-001). Verify: `tests/Unit/Service/FeedConsumerServiceTest.php` with mocked `IUserManager` and `IGroupManager`.

## 2. Keys

- [x] 2.1 Generate a key, store only its hash, return the key once (REQ-FCC-001, REQ-FCC-003). Verify: service test asserts the stored object never holds the secret.
- [x] 2.2 Verify a Bearer key in the OOAPI gate and act as the consumer account (REQ-FCC-002). Verify: `tests/Unit/Controller/OoapiControllerFeedKeyTest.php` (gate) and `tests/Unit/Service/FeedConsumerServiceTest.php` (valid, revoked, expired, wrong key).
- [x] 2.3 Refuse a consumer on another catalogue (REQ-FCC-002). Verify: same test class.
- [x] 2.4 Record `lastUsedAt` at most hourly and allow two active keys (REQ-FCC-003). Verify: service test with two keys.

## 3. Read rule and screen

- [x] 3.1 Change the OOAPI schemas' read rule to the catalogue's consumer group plus the instance list (REQ-FCC-002, REQ-FCC-004). Verify: an API test reads as a group member and is refused as another signed-in user not on the list.
      (Built: `lib/Service/FeedReadRule.php` writes the read rule onto the configured course, program and offering schemas. A static rule cannot name one group per catalogue, so the consumer half is a `$memberOf` rule over the `feedConsumer` objects: a signed-in caller reads an object when an enabled consumer acting as that caller belongs to the object's `catalog`, which is the same set as the catalogue's consumer group, scoped per catalogue. The instance half is the group `opencatalogi-ooapi-consumers`, kept equal to the `ooapi_consumers` list. An empty list means every account (REQ-FCC-004), so the rule then stays `authenticated`. Only these entries are replaced; other read entries, such as catalogue staff rules, are kept. Written by the `ApplyFeedReadRule` repair step after the register import and catalogue staff access, and again whenever the consumer list or an OOAPI schema setting is saved. PHPUnit: `FeedReadRuleTest`, `ApplyFeedReadRuleTest`, `SettingsFeedReadRuleTest`. Left: the API test as a consumer and as another signed-in user, which needs a live instance.)
- [x] 3.2 Add the Feed consumers section to the catalogue page, admin only (REQ-FCC-001, REQ-FCC-004). Verify: `tests/e2e/feed-consumers.spec.ts` adds a consumer, copies a key, calls the feed with it, revokes it.
      (Built: `src/components/catalogi/FeedConsumersPanel.vue` as the `feed-consumers` widget of `CatalogDetailPage.vue`, defined and laid out for administrators only; list with keys and the instance-wide accounts, add, generate (key shown once), revoke, disable, over `src/services/feedConsumers.js`. Unit-tested in `FeedConsumersPanel.spec.js`. Left: the e2e spec, which needs a live instance.)

## 4. Docs and strings

- [x] 4.1 Document issuing, rotating and revoking keys in `docs/`, and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
      (Built: `docs/features/feed-consumer-keys.md` gains "On the catalogue page"; the 9 new strings are in every shipped locale (`l10n/*.json`, `.js` rebuilt); `check:l10n` reports nothing missing or unused.)

## Notes from the build

- Admin endpoints: `GET|POST /api/catalogs/{slug}/consumers`, `POST .../consumers/{id}/keys`, `POST .../keys/{keyId}/revoke`, `POST .../consumers/{id}/disable` (`FeedConsumerController`, admin only). The listing also returns the instance-wide `ooapi_consumers` list read-only (REQ-FCC-004, backend half of 3.2).
- The OOAPI GET actions are now `@PublicPage` with the anonymous rate limit, so a request carrying only a key reaches the gate; the gate still answers 401 to a caller with neither a session nor a valid key. Their GET operations are documented in `openapi.standards.json`.
- A key consumer is not checked against the instance-wide list: the key itself is the grant, and the gate limits it to its own catalogue.
- 3.1: OpenRegister's schema read rule is static, so "this catalogue's consumer group" is written as a `$memberOf` rule over the `feedConsumer` objects instead of one group per catalogue (see 3.1 above). A later catalogue staff-access recompute that restores its stored rule can put `authenticated` back until the next settings save or upgrade; the gate's catalogue and list checks still apply on every OOAPI request.
- 3.2 (screen) and 4.1 (l10n strings in all locales) are left; `docs/features/feed-consumer-keys.md` documents issuing, rotating and revoking.
