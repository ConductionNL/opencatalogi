## Context

`PublicationsController::show(string $catalogSlug, string $id)` loads the catalogue by
slug, applies the anonymous read-rule guard (WOO-580), then looks the publication up with
`ObjectService::searchObjects(['@self' => ['uuid' => $id, 'register' => …, 'schema' => …]])`
for each register × schema of the catalogue, and falls back to
`PublicationQueryService::findObjectLocation(uuid: $id, …)` constrained to the same scope
(#733/#734). A catalogue without registers or schemas gets no fallback.

OpenRegister stores the slug in the `_slug` metadata column; `@self.slug` is an accepted
metadata filter of `searchObjects`. Since openregister#4037 a slug is unique per register
and schema and frozen once set. A catalogue spans several registers × schemas, so the
same slug can occur once per schema inside one catalogue.

## Goals / Non-Goals

**Goals:** a publication URL that survives a re-import; UUID URLs untouched; never the
wrong publication.

**Non-Goals:** uniqueness enforcement at save time across a catalogue (OpenRegister does
not know catalogues); sitemap, schema.org, federation and directory URLs; the website
template (separate issue); a redirect from UUID to slug URLs.

## Decisions

### D1 — UUID first, slug second

`show()` keeps its UUID lookup unchanged. Only when it found nothing does a slug lookup
run. A UUID URL therefore costs nothing extra and returns exactly what it did (PUB-020).

### D2 — Slug lookup across the whole catalogue scope, then count

For each register × schema of the catalogue, `searchObjects` with
`['@self' => ['slug' => $id, 'register' => $reg, 'schema' => $sch]]` and `_limit: 2`.
Matches are collected across all combinations, not stopped at the first hit, because a
second hit in another schema makes the slug ambiguous (PUB-019). Same `_rbac: true,
_multitenancy: false` as the UUID path, so the read rules stay identical.

The lookup never goes through an identifier that OpenRegister could read as a numeric
object id: it filters on `@self.slug` explicitly (PUB-020 numeric scenario).

### D3 — Ambiguous → 404 + warning, not 409

One match is returned. More than one gives the existing 404 body and a warning
`[PublicationsController] Slug "<slug>" is ambiguous in catalog <catalogSlug>: matches
publications <uuid-a>, <uuid-b>`. A 404 rather than a 409: the endpoint is anonymous and
public, a 409 would tell any caller that two publications share a case number, and a
citizen cannot act on it (issue plan Q8, working assumption pending Robert Zondervan).

### D4 — No new route, no UI code

`appinfo/routes.php` puts no format requirement on `{id}`. The detail view passes the
route id straight to this endpoint, so a slug in the hash route works once the endpoint
resolves it. Verified, not changed.

## Backwards compatibility

| Rule | How it holds |
|---|---|
| UUID URL unchanged | D1: the UUID path runs first and unchanged |
| Numeric slug never a numeric id | D2: explicit `@self.slug` filter |
| Publication without slug | reachable by UUID as before |
| Catalogue without scope | the slug lookup, like the UUID fallback, needs registers and schemas |
| Response shape | the same rendering path as the UUID hit |

## Seed Data (ADR-001)

Not applicable: no new schemas or registers.

## Declarative-vs-imperative decision (ADR-031)

Imperative: identifier resolution in a public controller, not object behaviour that an
`x-openregister-*` extension declares.

## Risks / Trade-offs

- **Extra queries on a miss**: a slug or unknown identifier costs one search per
  register × schema. Catalogues have a handful of combinations; the UUID path is
  unaffected.
- **Ambiguity hides a publication**: until the duplicate is resolved, neither is
  reachable by slug (both stay reachable by UUID). Visible in the log.

## Test strategy

- Unit (`tests/Unit/Controller/PublicationsControllerSlugTest.php`): UUID hit unchanged;
  slug hit; slug in another catalogue → 404; unknown slug → 404; two matches → 404 +
  warning without UUIDs in the body; numeric slug; catalogue without scope.
- Rig (NC 35, OpenRegister with #4037): catalogue `meting-4037` — slug 200, UUID 200,
  ambiguous slug across two schemas → 404 + warning.
