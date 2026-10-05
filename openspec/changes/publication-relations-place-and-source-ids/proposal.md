---
kind: code
depends_on: [openregister/relation-types-with-inverses, openregister/geometry-on-a-map]
---

# Proposal: publication-relations-place-and-source-ids

## Why

A decision that replaces an earlier one, an amendment to a regulation, a set of documents that belong together: a reader needs to see that link, from both sides. A source system needs to find its own record back by the number it knows. Neither is possible today.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **2.15** "A publication relates to another publication, with a named relation". Ours: no. Evidence: "no publication to publication relation. Nearest is #publication.themes, which groups records under a theme rather than naming a relation between two of them".
- **2.16** "A publication carries a geographic location". Ours: no. Evidence: "no geographic property on #publication. openregister supports geo on other schemas, and opencatalogi's publication does not use it". This row is flagged "deliberately not building" in the plan (reason: "DiWoo does not carry one, so the field is one nothing would read"), and decision D10 leaves it awaiting Ruben's strike or keep. Its requirement is written here and gated: it is not built until D10 keeps the row.
- **2.19** "A publication carries the identifiers its source systems use, and those are searchable". Ours: partial, production. Evidence: "opencatalogi #publication has no source identifier property; integriq synchronisation contracts hold the source id and openregister objects are searchable, so the data exists one app away and is not on the publication".

Read on development at 35999c296. The evidence for 2.19 is out of date in one respect: `lib/Settings/register.d/woo-dossier-publication.json` adds `caseReference` (one string, the source case), which dossiq's `WooPublicationService::buildPayload()` fills. It is one identifier from one system, not searchable by system. The publication detail manifest notes "geo/maps dropped, no geo field", so the maps widget of PUB-MAP-001 (archived change `publication-detail-leaf-widgets`) binds to a property the schema lacks. The public routes `publications#uses` and `publications#used` exist and read OpenRegister's relation rows.

## What changes

- The publication schema declares a relation vocabulary (`x-openregister-relation-types`): `replaces` (vervangt / wordt vervangen door), `amends` (wijzigt / wordt gewijzigd door), `implements` (geeft uitvoering aan / wordt uitgevoerd door) and `relatedTo` (hoort bij, symmetric). One array property per type, each a `$ref` to a publication carrying its `type`. OpenRegister's `uses` and `used` rows then carry the label for each direction.
- The public detail response carries `relations`: each relation with its label as read from this side, for related publications the reader may see. The officer page shows the same from both sides in the Related panel.
- The publication carries `sourceIdentifiers`: a list of `{system, identifier, public}`. `caseReference` is kept and mirrored into it as system `case`, so dossiq's current payload keeps working. The internal and public APIs filter on `sourceIdentifier=<system>:<identifier>`; the public one only on identifiers marked public.
- Gated on D10: the publication carries `geo` (GeoJSON, validated by OpenRegister's geometry support), emitted as `dct:spatial` in DCAT and as `geo` in the public API, and the maps widget of PUB-MAP-001 is placed again.

## Fail closed

- A related publication the reader cannot read is left out of `relations` on the public side. The relation label never reveals that a non-public record exists.
- A source identifier is returned publicly, and matched by the public filter, only when it is marked `public: true`. The default is false. An internal case number never reaches the public API by default.
- A relation to an object that is not a publication is refused by OpenRegister's `$ref` validation.

## Out of scope

- Search by area on the portal. portaliq owns its map search.
- Filling `sourceIdentifiers` from integriq synchronisations. integriq maps to the field through its own mapping; this change only provides it.
- Relations to objects outside the publication register.

## Dependencies

- `openregister/relation-types-with-inverses` (OpenRegister, open change outside this plan, 14 of 15 tasks; the open task is a hand-over to dossiq). `x-openregister-relation-types`, `RelationTypeResolver` and the labelled `uses` and `used` rows are on development at 1dc6a46.
- `openregister/geometry-on-a-map` (OpenRegister, open change outside this plan, 2 of 4 tasks). Only the gated 2.16 requirement needs it.
- `publication-lifecycle-on-or` (opencatalogi, planned, wave 1): its ready list gains the `sourceIdentifier` filter here.
- dossiq: no change needed. Its `WooPublicationService::buildPayload()` writes `caseReference`, which is mirrored. A later dossiq change may write `sourceIdentifiers` directly with system `dossiq`.

## Wave

Wave 2. It waits on two OpenRegister changes outside this plan, and it adds a filter to the wave 1 ready list.

## Decisions

- D10: 2.16 is flagged and awaits strike or keep. Its requirement (REQ-PRS-004) and tasks (group 5) are gated: the builder skips them unless Ruben has kept 2.16. The other two rows are built.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 2.15 | A publication relates to another publication, with a named relation | no | REQ-PRS-001 and REQ-PRS-002, scenario "Both sides read the relation" |
| 2.16 | A publication carries a geographic location | no | gated on D10: REQ-PRS-004, scenario "A publication with a place" |
| 2.19 | A publication carries the identifiers its source systems use, and those are searchable | partial | REQ-PRS-003, scenarios "A source system finds its record by its own number" and "An internal number stays internal" |
