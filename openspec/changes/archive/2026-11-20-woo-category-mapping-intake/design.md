## Context

See `proposal.md` — Why. Two facts shape the approach:

1. **opencatalogi already owns the canonical vocabulary.** `TooiVocabularyService` bundles the 17 official
   Woo informatiecategorieen as `code → {id, label, aliases}` (`lib/Service/TooiVocabularyService.php:62-104`)
   and exposes them publicly via `informatiecategorieList(): array<code, {uri, label}>`
   (`lib/Service/TooiVocabularyService.php:217-224`). `SitemapService::mapDiwooDocument()` already resolves a
   *per-publication* category value against this vocabulary
   (`lib/Service/SitemapService.php:546-565`, calling `resolveInformatiecategorie()` at
   `lib/Service/TooiVocabularyService.php:138-154`). There is no equivalent *per-type default* concept in
   opencatalogi today — every publication must carry its own category or the axis is omitted and reported as
   a violation.
2. **decidesk's mapping is a per-type default, seeded with placeholders.** decidesk's `WooCategorieMapping`
   (`decidesk/lib/Settings/register.d/58-woo-diwoo-publication.json:87-181`) is exactly that missing
   per-type-default concept, but scoped to decidesk's own 8 schema slugs, with a closed `enum` on `objectType`
   (`...:142-152`), and every one of its 8 seed rows carries a `c_PLACEHOLDER_...` URI
   (`...:10,18,26,34,42,50,58,66`) rather than a real TOOI concept URI, because the mapping's seed author had
   no access to `TooiVocabularyService`'s real value list from inside decidesk.

## Goals / Non-Goals

**Goals:**
- Generalise the mapping's key from a single closed enum to `(app, objectType)` so any Conduction app can
  register a default without touching opencatalogi code.
- Resolve every migrated row against the real vocabulary at intake time, closing the placeholder gap.
- Give `SitemapService` a per-type-default fallback for its own publications, and give every other publishing
  app a way to read the same defaults without a bespoke API.

**Non-Goals:**
- Retiring decidesk's `WooCategorieMapping` schema/data — that is the named companion change
  (`decidesk-retire-woo-category-mapping`), sequenced strictly after this one via `depends_on`.
- Building `DiWooMetadataService`, `WooIndexController`, or `WooIndexConnectorService` — decidesk's own
  seed-data comment (`58-woo-diwoo-publication.json:4`) already scopes those as separate, later, imperative
  work; this change only has to make the mapping *readable* by whatever consumes it next.
- Changing the 17-member TOOI vocabulary itself, or `TooiVocabularyService`'s public surface.
- An admin UI for editing mapping rows. Rows ship as seed data; a future change can add UI if a real admin
  workflow need appears (default-per-type mappings change rarely — this is the same judgement decidesk's own
  seed comment made for `WooBestuursorgaan`).

## Decisions

### D1 — Key by `(app, objectType)`, not `objectType` alone

decidesk's `objectType` enum is closed to 8 decidesk-specific strings. Two apps' schema slugs can collide
(`"decision"` is a plausible slug in more than one domain), so uniqueness must be scoped by the owning app.
Alternative considered: prefix `objectType` with the app name (`"decidesk:decision"`) to avoid a second
field — rejected because it turns a structured value into string-parsing convention or duplicates a filter
that a normal indexed property already handles, and it forces every consumer to remember the delimiter.

### D2 — Store the resolved URI + label directly on the row, not a lookup code

Two options: (a) store `informatiecategorieCode` (e.g. `"infocat008"`) and require every consumer to
call back into `TooiVocabularyService` to resolve it, or (b) store the resolved `informatiecategorie` URI +
`informatiecategorieLabel` directly on the row (decidesk's original shape, minus placeholders), pattern-validated
against the TOOI URI prefix exactly as decidesk's schema already did
(`58-woo-diwoo-publication.json:158`).

Chose (b). `TooiVocabularyService` is a PHP class private to opencatalogi; a cross-app consumer reading the
mapping via a plain OpenRegister object query (D4) cannot call it. Storing the resolved URI + label makes the
mapping self-contained and readable by any app with zero PHP coupling — the OR object *is* the API. The
17-member vocabulary changes rarely (KOOP publishes revisions infrequently), so the durability cost of
"resolved value can drift from the source list" is low, and is caught the same way decidesk's original schema
already caught it: pattern validation on write, `informatiecategorieLabel` shown alongside the URI for human
review, and this change's own intake step re-resolving every row from the *current* vocabulary rather than
hand-copying the old placeholders.

### D3 — `objectType` becomes a free-text string, not an enum

An enum forces opencatalogi to edit its own schema every time a new app or a new schema slug wants a mapping.
A free-text string (still `required`, still indexed/facetable for the admin list view) lets any app register a
row without a schema change here. Duplicate-key detection ((D1) uniqueness) is a review/validation concern,
same posture decidesk's schema already documented for its own single-field uniqueness
(`58-woo-diwoo-publication.json:141`: "OR has no cross-object unique constraint — uniqueness is a
review/validation concern").

### D4 — Consumption is a direct OpenRegister object query, not a new controller

Per ADR-022 (apps consume OpenRegister abstractions) and the existing pattern in this very codebase —
`WooReadinessService::getWooEnabledCatalogs()` already resolves another schema's objects via
`ObjectService::searchObjectsPaginated()` with an `@self` register/schema filter plus a property filter
(`lib/Service/WooReadinessService.php:243-257`) — a bespoke `WooCategoryMappingController` or
`WooCategoryMappingService::resolveFor()` RPC surface for other apps to call would be exactly the "phantom
cross-app RPC" pattern the fleet gate `hydra-gate-no-phantom-cross-app-rpc` exists to catch: PHP classes
cannot be called across app boundaries at all, so the only real consumption path was always the OR object API.
opencatalogi's own `SitemapService` consumes it the same way, for consistency, even though it *could*
theoretically inject a new opencatalogi-local service — using the same path proves the path actually works
for a stranger app before decidesk depends on it.

### D5 — `SitemapService`'s fallback stays inside `mapDiwooDocument()`, not a new service class

The fallback is three lines of logic (look up a mapping row, use its `informatiecategorie`/`Label` instead of
treating the axis as unresolved) inserted into the existing branch at
`lib/Service/SitemapService.php:546-565`. It shares the same `$violations`-reporting contract as every other
axis in that method. A new service class for a three-line lookup would be the kind of gratuitous indirection
`hydra-gate-redundant-controller` flags on the wrapper side of that pattern.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path chosen | Rationale |
|---|---|---|
| Type-level default resolution for `diwoo:informatiecategorie` when a publication declares no category | **Imperative** — extends the existing imperative `SitemapService::mapDiwooDocument()` | Exception category: *document generation* / *external integration*. This method already generates a DIWOO XML document for the national Woo-index (KOOP), an external standard with its own resolution and fallback rules (see WOO-TOOI-001..004) that predate and are unrelated to OpenRegister's declarative primitives. The fallback is one more branch in an already-imperative, already-spec'd resolution chain — not a new derived/calculated field on an object that x-openregister-calculations would be a better fit for. |
| Storing the mapping rows themselves | **Declarative** — plain OR schema + seed data, no lifecycle, no service class needed to read them | Mapping rows are static configuration (per decidesk's own prior schema comment: "mappings have no lifecycle... deliberately no x-openregister-lifecycle"). No new precedent needed; carried over unchanged from the schema this change generalises. |

## Migration Plan

1. Add the `WooCategoryMapping` schema + attach it to the `publication` register via a new
   `lib/Settings/register.d/*.json` fragment (ADR-037 pattern — mirrors
   `lib/Settings/register.d/fix-woo-capability-provisioning.json`), so it gets a magic table and shows up in
   the admin schema selector without editing the monolithic `publication_register.json`.
2. Seed the 8 migrated rows in the same fragment (see Seed Data below) — each row's `informatiecategorie` /
   `informatiecategorieLabel` is the **real** resolved value from `TooiVocabularyService::informatiecategorieList()`,
   not the placeholder decidesk shipped.
3. Extend `SitemapService::mapDiwooDocument()` with the fallback lookup (D5).
4. No rollback complexity: this change adds a new schema and a new fallback branch; it does not modify or
   remove any existing opencatalogi behavior. Rollback is "revert the fragment + the `SitemapService` diff."
5. decidesk-side retirement is explicitly deferred to the companion change — this migration plan does not
   touch decidesk.

## Seed Data

Migrated from `decidesk/lib/Settings/register.d/58-woo-diwoo-publication.json:6-71`, `app: "decidesk"` added
to every row, and every placeholder URI resolved against
`TooiVocabularyService::informatiecategorieList()` (`lib/Service/TooiVocabularyService.php:62-104`):

| slug | app | objectType | informatiecategorie (resolved) | label | active | notes |
|---|---|---|---|---|---|---|
| `woo-map-decidesk-meeting-agenda` | decidesk | `meeting-agenda` | `.../c_db4862c3` | Vergaderstukken decentrale overheden | true | Meeting agenda payload. Migrated from decidesk `woo-map-meeting-agenda`; placeholder resolved to infocat008. |
| `woo-map-decidesk-decision-list` | decidesk | `decision-list` | `.../c_db4862c3` | Vergaderstukken decentrale overheden | true | Generated decision-list document. Migrated from decidesk `woo-map-besluitenlijst`; placeholder resolved to infocat008. |
| `woo-map-decidesk-minutes` | decidesk | `minutes` | `.../c_db4862c3` | Vergaderstukken decentrale overheden | true | ORI Verslag. Migrated from decidesk `woo-map-minutes`; placeholder resolved to infocat008. |
| `woo-map-decidesk-decision` | decidesk | `decision` | `.../c_db4862c3` | Vergaderstukken decentrale overheden | true | ORI Besluit. Migrated from decidesk `woo-map-decision`; placeholder resolved to infocat008. |
| `woo-map-decidesk-motion` | decidesk | `motion` | `.../c_db4862c3` | Vergaderstukken decentrale overheden | true | Inert until decidesk's Motie schema lands (WOO-CAT-004). Migrated from decidesk `woo-map-motie`; placeholder resolved to infocat008. |
| `woo-map-decidesk-commitment` | decidesk | `commitment` | `.../c_db4862c3` | Vergaderstukken decentrale overheden | true | Predicate-published. Migrated from decidesk `woo-map-toezegging`; placeholder resolved to infocat008. |
| `woo-map-decidesk-council-information-letter` | decidesk | `council-information-letter` | `.../c_db4862c3` | Vergaderstukken decentrale overheden | true | Predicate-published. Migrated from decidesk `woo-map-raadsinformatiebrief`; placeholder resolved to infocat008. |
| `woo-map-decidesk-arrangement` | decidesk | `arrangement` | `.../c_139c6280` | Wetten en algemeen verbindende voorschriften | true | Inert until decidesk's Regeling schema lands (WOO-CAT-004). Migrated from decidesk `woo-map-regeling`; placeholder resolved to infocat001. |

(URIs abbreviated to their `c_...` suffix for table width; full form is
`https://identifier.overheid.nl/tooi/def/thes/kern/c_db4862c3` and
`https://identifier.overheid.nl/tooi/def/thes/kern/c_139c6280` respectively — both taken verbatim from
`TooiVocabularyService::INFORMATIECATEGORIEEN['infocat008'|'infocat001']`,
`lib/Service/TooiVocabularyService.php:80-90,63-67`.)

No general-organization example row is added beyond the migrated decidesk set — the capability's only
current consumer is decidesk, and inventing a synthetic "municipality" or "consultancy" row without a real
consumer would just recreate the placeholder problem this change exists to close.

## Risks / Trade-offs

- **[Risk] The resolved URIs above were mapped by re-reading decidesk's `informatiecategorieLabel` text
  against opencatalogi's vocabulary, not by an automated tool** → **Mitigation**: both of decidesk's two
  distinct label strings ("Vergaderstukken decentrale overheden", "Wetten en algemeen verbindende
  voorschriften") are exact, case-identical matches to `TooiVocabularyService::INFORMATIECATEGORIEEN['infocat008'|'infocat001']['label']`,
  so the mapping is a label-identity match, not a judgement call. A reviewer should re-verify by diffing the
  seed fragment's labels against `lib/Service/TooiVocabularyService.php:62-104` at implementation time.
- **[Risk] `SitemapService::mapDiwooDocument()`'s fallback introduces one more OR query per document with no
  declared category** → **Mitigation**: bounded by the same publication-batch size the method already
  iterates; the mapping table has at most a handful of rows per app, so this is a small indexed lookup, not an
  unbounded scan (`hydra-gate-listener-work-placement` does not apply — this executes inside an already-paginated
  request handler, not a listener on a write path).
- **[Risk] A future app registers a mapping row with the wrong `app` value (typo) and silently gets no
  fallback** → **Mitigation**: WOO-CAT-004 already treats "no match" as inert/non-blocking by design — a typo
  just means no default applies, which is the same safe failure mode as today (axis omitted, violation
  reported), not a wrong category being emitted.
- **[Trade-off] Mixed-spec shape** — see `proposal.md` "Mixed-spec rationale". Accepted for this iteration;
  flagged for human confirmation rather than silently split.

## Open Questions

- Should the mapping schema additionally validate `objectType` against a known-schemas list at write time
  (rather than leaving unknown types purely inert per WOO-CAT-004)? Left open — the "inert, never blocking"
  behavior is deliberately the same posture decidesk's own schema already chose for exactly this situation.
