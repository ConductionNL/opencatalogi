# Tasks: integration-feed-consumer-credentials

## 1. Schema and accounts

- [ ] 1.1 Add `feedConsumer` in `lib/Settings/register.d/feed-consumer-credentials.json` (REQ-FCC-001). Verify: clean `occ app:enable` imports it; `tests/Unit/Settings/RegisterFragmentTest.php`.
- [ ] 1.2 Create and disable consumer accounts in the catalogue's consumer group (REQ-FCC-001). Verify: `tests/Unit/Service/FeedConsumerServiceTest.php` with mocked `IUserManager` and `IGroupManager`.

## 2. Keys

- [ ] 2.1 Generate a key, store only its hash, return the key once (REQ-FCC-001, REQ-FCC-003). Verify: service test asserts the stored object never holds the secret.
- [ ] 2.2 Verify a Bearer key in the OOAPI gate and act as the consumer account (REQ-FCC-002). Verify: `tests/Unit/Controller/OoapiControllerTest.php` with a valid, a revoked, an expired and a wrong key.
- [ ] 2.3 Refuse a consumer on another catalogue (REQ-FCC-002). Verify: same test class.
- [ ] 2.4 Record `lastUsedAt` at most hourly and allow two active keys (REQ-FCC-003). Verify: service test with two keys.

## 3. Read rule and screen

- [ ] 3.1 Change the OOAPI schemas' read rule to the catalogue's consumer group plus the instance list (REQ-FCC-002, REQ-FCC-004). Verify: an API test reads as a group member and is refused as another signed-in user not on the list.
- [ ] 3.2 Add the Feed consumers section to the catalogue page, admin only (REQ-FCC-001, REQ-FCC-004). Verify: `tests/e2e/feed-consumers.spec.ts` adds a consumer, copies a key, calls the feed with it, revokes it.

## 4. Docs and strings

- [ ] 4.1 Document issuing, rotating and revoking keys in `docs/`, and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
