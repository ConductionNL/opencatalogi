# What changed since: keeping a copy of a catalogue current

A re-user who keeps a copy of a catalogue does not have to read the whole DCAT feed every
time. One route per catalogue answers two questions: which publications changed since a
moment, and which were withdrawn.

```
GET /index.php/apps/opencatalogi/api/v1/{catalogSlug}/changes?since=2026-11-12T00:00:00Z
```

The route is public and read-only. It answers GET and its CORS preflight, and nothing else.

## The answer

```json
{
  "changed": [ { "@id": "https://example.org/.../dataset/...", "@type": "dcat:Dataset" } ],
  "withdrawn": [ { "id": "4f6c...", "withdrawnAt": "2026-11-13T08:00:00Z" } ],
  "since": "2026-11-12T00:00:00Z",
  "until": "2026-11-13T12:00:00Z",
  "next": null
}
```

- `changed` holds a DCAT dataset node for every publication an anonymous visitor may read
  whose last change is at or after `since`. The nodes are the same ones the catalogue's
  DCAT feed renders. They are ordered by last change, then by id.
- `withdrawn` names each publication of the catalogue that was taken down at or after
  `since`, by id and date only. It carries no title, summary or file.
- `until` is the moment the answer was read. Use it as `since` the next time you ask.
- `next` is the URL of the next page, or null on the last page. A page holds at most 100
  entries. Every page of one walk is pinned to the same `until`, and `withdrawn` is on the
  first page only.

## What never appears

- A draft, a scheduled publication or any publication an anonymous visitor may not read is
  never in `changed`. The read runs as an anonymous visitor, whoever asks. When the
  OpenRegister installation cannot read as an anonymous visitor, the route answers 503
  rather than reading with the caller's rights.
- A publication that was never public is never in `withdrawn`. A publication is listed only
  when a depublication record shows it was taken down. A depublication of a single document
  does not list the publication.

## Errors

| Status | When |
|---|---|
| 400 | `since` is missing or not an ISO 8601 date, such as `2026-04-01` or `2026-04-01T00:00:00Z`; or `cursor` is not one the route issued. The body names the parameter. |
| 404 | The catalogue does not exist. |
| 503 | OpenRegister is not installed, or cannot read as an anonymous visitor. |

The route allows 60 requests a minute from one anonymous address.
