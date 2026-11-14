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

- [ ] 3.1 Change the OOAPI schemas' read rule to the catalogue's consumer group plus the instance list (REQ-FCC-002, REQ-FCC-004). Verify: an API test reads as a group member and is refused as another signed-in user not on the list.
- [ ] 3.2 Add the Feed consumers section to the catalogue page, admin only (REQ-FCC-001, REQ-FCC-004). Verify: `tests/e2e/feed-consumers.spec.ts` adds a consumer, copies a key, calls the feed with it, revokes it.

## 4. Docs and strings

- [ ] 4.1 Document issuing, rotating and revoking keys in `docs/`, and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.

## Notes from the build

- Admin endpoints: `GET|POST /api/catalogs/{slug}/consumers`, `POST .../consumers/{id}/keys`, `POST .../keys/{keyId}/revoke`, `POST .../consumers/{id}/disable` (`FeedConsumerController`, admin only). The listing also returns the instance-wide `ooapi_consumers` list read-only (REQ-FCC-004, backend half of 3.2).
- The OOAPI GET actions are now `@PublicPage` with the anonymous rate limit, so a request carrying only a key reaches the gate; the gate still answers 401 to a caller with neither a session nor a valid key. Their GET operations are documented in `openapi.standards.json`.
- A key consumer is not checked against the instance-wide list: the key itself is the grant, and the gate limits it to its own catalogue.
- 3.1 is left: OpenRegister's schema read rule is static, so "this catalogue's consumer group" cannot be written on the shared course/program/offering schemas without a design decision; the gate's catalogue check enforces the scope meanwhile.
- 3.2 (screen) and 4.1 (l10n strings in all locales) are left; `docs/features/feed-consumer-keys.md` documents issuing, rotating and revoking.
