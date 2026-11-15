# Applied filters: what a public list filtered on its own

A public list answers fewer rows than an integrator may expect, because the server narrows every query before it reaches OpenRegister: to the catalogue's own scope, to what an anonymous reader may see, and without archived publications. The `appliedFilters` key on every list response says which of these applied to that answer, so a short list can be told apart from a missing one.

## Where it appears

- `GET /api/{catalogSlug}` (`publications#index`)
- `GET /api/search` (`search#index`)
- `GET /api/themes` (`themes#index`)
- `GET /api/federation/publications` (`federation#publications`)

The key is on the 200 answer of each. An error answer does not carry it.

## The shape

```json
"appliedFilters": [
  { "name": "catalogScope", "value": { "schemas": ["publication", "besluit"], "registers": ["woo"] }, "reason": "The list is limited to the schemas and registers of the catalogue." },
  { "name": "publicOnly", "value": true, "reason": "Only what an anonymous reader may see is listed." },
  { "name": "archivedExcluded", "value": 2, "reason": "Archived publications are never shown on a public list." },
  { "name": "callerScopeIgnored", "value": ["_schemas"], "reason": "A public list sets its own register and schema scope; these query keys were ignored." }
]
```

Each entry has a `name`, a `value` and a `reason`. The names are stable API; the `reason` is a short English sentence for a person reading the answer, and may be reworded.

| name | when it is listed | value |
|---|---|---|
| `catalogScope` | the catalogue's configured schemas and registers narrowed the query | `{schemas, registers}`, as slugs |
| `publicOnly` | the query ran as the anonymous public reader | `true` |
| `archivedExcluded` | rows with status `archived` were dropped from this page | the number dropped on this page |
| `callerScopeIgnored` | the caller sent its own scope keys (`register`, `schema`, `_register`, `_registers`, `_schema`, `_schemas`, or the same under `@self`) and they were removed | the removed key names |
| `unknown` | the server could not read which filters applied | `null` |

## Reading it

- A filter that did not apply has no entry. An empty list means the server filtered nothing beyond the caller's own parameters.
- `archivedExcluded` counts the current page only: a page of 20 that shows 18 rows says 2.
- A caller cannot widen a public list. Sending `_schemas[]=secret` to `GET /api/woo` answers the catalogue's own rows, with `callerScopeIgnored` saying the key was ignored.
- A single `unknown` entry means the filters could not be read. Treat it as "filtered, but not said how", never as "nothing filtered".

The schema is the `AppliedFilters` component in `openapi.json` and `openapi.standards.json`; the `OpenApiParityTest` keeps every documented list response referencing it.
