# Design: publication-translations

## D1. What OpenRegister owns

| Part | Where on openregister development |
|---|---|
| Storage of translations, per property and language | `openregister_translations`, `TranslationProjectionService` |
| Read negotiation (`_lang`, `language`, `Accept-Language`, register default, `nl`) | spec `i18n-api-language-negotiation`, `ObjectsController` |
| Writing one language (`X-Translation-Target-Language`) | same spec, REQ on POST/PUT/PATCH |
| Source language per property, outdated flag when the source changes | spec `i18n-source-of-truth`, `TranslationStatusService` |
| Completeness per object | `GET /api/translations/object/{uuid}` (`TranslationController::showByObject`) |

OpenCatalogi builds no translation table, no fallback logic and no completeness count (ADR-022).

## D2. Schema

`lib/Settings/publication_register.json`: on `publication` and `catalog`, properties `title`, `summary`, `description` get `"translatable": true, "sourceLanguage": "nl"`. Versions bumped. Search: OpenRegister indexes the source value; a translated search is not in scope.

## D3. Public API

`PublicationQueryService` runs OpenRegister as anonymous for the public endpoints. It reads the language from the request (`_lang` query, then `Accept-Language`), passes it as `_lang` to the OpenRegister read, and the controllers set `Content-Language` to the language served and pass `X-Source-Language` through. An unknown or invalid tag falls through to Dutch, as OpenRegister does; never a 400. Federation reads (`DirectoryService`) forward the caller's language to the peer. `DcatMappingService` writes `dct:title`, `dct:description` once per language present, each with `@language`.

## D4. Edit page (board `OcPublicatieBewerken`)

A segmented control labelled "Language" above Title: "Dutch, source" and each enabled language. Under it the line "Title, summary and description are translatable. Empty fields fall back to Dutch." In another language the non-translatable fields (organisation, Woo category, dates, retention) are read-only with the hint "Same in every language". Save sends the three fields with `X-Translation-Target-Language`. A field whose translation is outdated shows "Dutch changed since this was translated". The same control appears on the catalogue edit page.

## D5. Publication page (board `OcPublicatie`)

Card "Languages": one row per enabled language. Dutch: "Source language, complete". Others from `GET /apps/openregister/api/translations/object/{uuid}`: "Title and summary translated, description missing", "complete", or "outdated: summary". Button "Translate in the editor" opens the edit page in that language. The card's footnote: "The public API answers in the language the visitor asks for and falls back to Dutch."

## D6. Settings

IAppConfig key `publication_languages` (JSON list of BCP 47 tags, default `["nl","en"]`), added to the admin-settings inventory, edited on the settings page with an `NcSelect` (`inputLabel` "Languages offered to editors"). Board `OcInstellingen` has no place for it; it goes under Publishing options.

## D7. Tests

Unit: `PublicationQueryServiceTest::testTheVisitorsLanguageIsPassedToOpenRegister`, `::testAnInvalidTagFallsBackToDutch`; `DcatMappingServiceTest::testEachLanguageIsATaggedLiteral`; schema test on the `translatable` flags. e2e: write an English summary, read it anonymously with `Accept-Language: en`, read the Dutch fallback for the untranslated description, see the Languages card.
