# Tasks: woo-metadata-suggestions

## 1. Schema

- [ ] 1.1 Add `metadataSuggestion` with its lifecycle and pending-count aggregation in `lib/Settings/register.d/woo-metadata-suggestions.json`, plus the seed suggestion (REQ-WMS-001). Verify: a clean `occ app:enable` imports the schema; `tests/Unit/Settings/RegisterFragmentTest.php` reads the fragment.

## 2. Rules floor

- [ ] 2.1 Add `WooMetadataSuggestionService::suggestFromRules()` for category, organisation and handling type, checked against `TooiVocabularyService` (REQ-WMS-001). Verify: `tests/Unit/Service/WooMetadataSuggestionServiceTest.php`, one case per field and one for an unresolvable value.
- [ ] 2.2 Queue the rules floor when a publication is created (REQ-WMS-001). Verify: listener test on a real `ObjectCreatedEvent`.

## 3. Hermiq

- [ ] 3.1 Add `HermiqMetadataClient` and the `source=hermiq` path of `POST /api/publications/{id}/metadata-suggestions`, answering 409 without Hermiq (REQ-WMS-002). Verify: controller test with and without the client.

## 4. A person decides

- [ ] 4.1 Add accept and reject endpoints with the publication's update right checked (REQ-WMS-003). Verify: `tests/Unit/Controller/MetadataSuggestionControllerTest.php`, including a user without update rights.
- [ ] 4.2 Add the `metadata-suggestions` widget and place it on `PublicationDetail` in `src/manifest.json` (REQ-WMS-003). Verify: `tests/e2e/woo-metadata-suggestions.spec.ts` accepts one suggestion and sees the field filled.

## 5. Docs and strings

- [ ] 5.1 Document the suggestions for editors in `docs/` and add the strings to `l10n/` in English and Dutch. Verify: `npm run lint` and the l10n check.
