# Publication relations and source identifiers

A publication can name how it relates to another publication, and it can carry the numbers its source systems know it by. Both are plain properties of the `publication` schema, declared in `lib/Settings/register.d/publication-relations-place-and-source-ids.json`.

## Relations

Four relation properties each hold a list of publication ids:

| Property | Read from this side | Read from the other side |
|---|---|---|
| `replaces` | replaces | is replaced by |
| `amends` | amends | is amended by |
| `implements` | implements | is implemented by |
| `relatedTo` | is related to | is related to |

The labels come from OpenRegister's relation vocabulary (`x-openregister-relation-types`), in English and Dutch. You store a relation once, on one publication. OpenRegister reads it from both sides.

A publication cannot relate to itself. A save that puts its own id in one of the four properties is refused, and the error names the property.

### On the public API

`GET /api/{catalogSlug}/{id}` carries `relations`:

```json
"relations": [
  {
    "type": "replaces",
    "label": "vervangt",
    "direction": "outgoing",
    "id": "…",
    "title": "Parkeerbesluit 2024",
    "url": "https://…/index.php/apps/opencatalogi/api/woo/…"
  }
]
```

A related publication is listed only when the same public read would return it. It must sit in the catalogue's registers and schemas and pass the schema's read rules. For an anonymous reader it must also be published now. A relation to a draft is left out, and nothing in the answer names the draft. If the relations cannot be read, the list is empty.

`GET /api/{catalogSlug}/{id}/uses` and `/used` keep answering OpenRegister's rows as before. Each row carries a `relation` block with the property, the direction and the label for that direction.

## Source identifiers

`sourceIdentifiers` is a list of entries:

| Field | Required | Meaning |
|---|---|---|
| `system` | yes | the source system, such as `case` or `decos` |
| `identifier` | yes | the number that system uses |
| `public` | no, default `false` | whether the entry may be shown on the public API |

When a publication is saved with a `caseReference`, that reference is added as `{system: "case", identifier: <caseReference>, public: false}`, unless the entry is already there. Existing entries are never removed. A repair step adds the same entry once to every stored publication.

### On the public API

Public answers carry only the entries with `public: true`: the detail, the catalogue list, the federation reads and the search. An entry without the flag stays internal.

### Filtering for integrators

Not built yet. The change also asks for a `sourceIdentifier=<system>:<identifier>` filter on the internal list, the ready list and the public list and search. The public filter would only match public entries.
