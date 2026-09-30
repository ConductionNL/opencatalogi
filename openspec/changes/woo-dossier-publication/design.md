# Design: woo-dossier-publication

## D1 · Publication properties

Added through the fragment `lib/Settings/register.d/woo-dossier-publication.json`, which deep-merges onto `components.schemas.publication.properties` and raises the publication schema version.

| Property | Type | Notes |
|---|---|---|
| `publicationKind` | enum `woo-besluit`, `actief` | facetable |
| `caseReference` | string, max 255 | The case the publication came from (dossiq case id or reference) |
| `period` | object `{ from: date, to: date }` | The period the documents cover |

`wooCategory` stays the information category. Its enum is the TOOI list; the contract's "informatiecategorie" is this property.

## D2 · Search vocabulary

`SearchQueryTranslator::translateSearchParams(array $params): array` renames, before `assemblePublicSearchResults()` sees the query:

| Portal name | Search parameter |
|---|---|
| `informatiecategorie` / `informatiecategorie[]` | `wooCategory` |
| `organisation` / `organisation[]` | `organization` |
| `periodFrom` | `publicationDate[gte]` |
| `periodTo` | `publicationDate[lte]` |

A name the caller also sent in its search form wins (a caller who sends `wooCategory` directly keeps it). The range bounds then pass the existing `SearchRangeGuard`, so a malformed date answers 400 as before. `SearchController::index()` applies the translation; the saved-search job calls the same translator (`saved-searches-and-alerts`, D2).

## Risks

A fragment that deep-merges into the publication schema changes the base schema for every install. The three properties are optional and `hardValidation` stays false, so existing publications stay valid.
