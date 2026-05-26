# Public API: `_extend=files` opt-in for attachment metadata

## Background

OpenCatalogi exposes publications and their attachments through the public, catalog-scoped API:

- `GET /api/{catalogSlug}` — list publications in a catalog
- `GET /api/{catalogSlug}/{id}` — show a single publication
- `GET /api/{catalogSlug}/{id}/attachments` — full attachment metadata for one publication

Publications are stored as OpenRegister objects, and the `@self.files` field on every publication response is sourced from OpenRegister. As of the [opt-in-files-extend change in OpenRegister](https://github.com/ConductionNL/openregister/tree/main/openspec/changes/opt-in-files-extend), the shape of `@self.files` is **opt-in** rather than always-full — and OpenCatalogi inherits that contract automatically without any code change in this repo.

This document describes the new contract for OpenCatalogi public-API consumers (frontends, federated mirrors, scrapers).

## What changed

| Before                                                  | After                                                                                |
|---------------------------------------------------------|--------------------------------------------------------------------------------------|
| `@self.files` always contained full attachment metadata | `@self.files` defaults to a list of file IDs only                                    |
| One implicit Nextcloud-files lookup per response row    | One batched lookup per request, regardless of page size                              |
| No way to opt out of full metadata                      | Full metadata available on demand via `?_extend[]=@self.files` or `?_extend[]=_files`|

The default response is now smaller and cheaper. Consumers that need the previous shape add the `_extend` opt-in. Both `@self.files` and the `_files` shorthand are accepted and are byte-identical.

## Default response shape (no `_extend`)

```bash
curl -s "https://example.org/index.php/apps/opencatalogi/api/{catalogSlug}/{publicationId}" | jq '."@self".files'
```

```json
[
  142,
  287,
  314
]
```

`@self.files` is a list of integer file IDs. Publications with no attachments produce an empty array (`[]`), never `null`.

## Opt-in response shape (`_extend[]=@self.files`)

```bash
curl -s "https://example.org/index.php/apps/opencatalogi/api/{catalogSlug}/{publicationId}?_extend[]=@self.files" | jq '."@self".files'
```

```json
[
  {
    "id": 142,
    "title": "kamerbrief.pdf",
    "path": "/openregister/publications/{uuid}/attachments/kamerbrief.pdf",
    "downloadUrl": "https://example.org/index.php/apps/openregister/download?fileId=142",
    "size": 184921,
    "mimetype": "application/pdf",
    "...": "..."
  }
]
```

Use `_extend[]=@self.files` (or its shorthand `_extend[]=_files`) when you genuinely need download URLs, file titles, sizes, or MIME types in the same response as the publication metadata. The two spellings produce identical output.

## List endpoints — performance warning

> **Using `_extend[]=@self.files` (or `_files`) on list endpoints is heavily discouraged because of computational cost. It causes one file lookup per row and will result in degraded performance. Use it only when full file metadata is genuinely required for every row of the list.**

For list responses (`GET /api/{catalogSlug}`):

- The default shape (file IDs only) batches the lookup into a single query, regardless of `_limit`.
- Adding `_extend[]=@self.files` switches to a per-row file lookup. On a page of 50 publications, this means 50 extra file lookups.
- If you need full attachment metadata for many publications at once, prefer one of:
  - Render the list with default IDs, then resolve full metadata only for the publications the user expands.
  - Call `GET /api/{catalogSlug}/{id}/attachments` per publication on demand.

```bash
# DEFAULT — cheap, single batched lookup
curl -s "https://example.org/index.php/apps/opencatalogi/api/{catalogSlug}?_limit=50" \
  | jq '.results[0]."@self".files'

# OPT-IN — discouraged on list endpoints
curl -s "https://example.org/index.php/apps/opencatalogi/api/{catalogSlug}?_limit=50&_extend[]=@self.files" \
  | jq '.results[0]."@self".files'
```

## Show endpoint — both shapes are cheap

For the single-object endpoint (`GET /api/{catalogSlug}/{id}`), the cost difference between default and `_extend` is one row's worth, so use whichever fits the consumer.

```bash
# DEFAULT — IDs only
curl -s "https://example.org/index.php/apps/opencatalogi/api/{catalogSlug}/{id}" \
  | jq '."@self".files'

# OPT-IN — full metadata
curl -s "https://example.org/index.php/apps/opencatalogi/api/{catalogSlug}/{id}?_extend[]=@self.files" \
  | jq '."@self".files'
```

## When to use the dedicated `attachments` endpoint

`GET /api/{catalogSlug}/{id}/attachments` is the documented path for retrieving full attachment metadata for a single publication. It is the recommended call when:

- A consumer is rendering a single publication's attachment list.
- A consumer needs richer attachment fields than `@self.files` carries.
- A consumer is hitting the show endpoint and an extra round-trip is acceptable.

The `attachments()` endpoint is unchanged by the `_extend` work and remains the cleanest path for one-publication-many-attachments use cases.

## `_files` shorthand

`_extend[]=_files` is normalised to `_extend[]=@self.files` upstream in OpenRegister and produces a byte-identical response. Either spelling is acceptable; pick one and stay consistent.

```bash
diff \
  <(curl -s "$BASE/{catalogSlug}/{id}?_extend[]=_files"      | jq -S .) \
  <(curl -s "$BASE/{catalogSlug}/{id}?_extend[]=@self.files" | jq -S .)
# expected: empty diff
```

## Migration checklist for consumers

If your consumer currently reads `downloadUrl`, `path`, `title`, or any other property off `@self.files[i]`, do one of:

1. **Add `_extend[]=@self.files`** to the request. Lowest-effort migration; preserves the previous response shape.
2. **Read the lightweight ID list** (`@self.files: [142, 287, …]`) and resolve metadata via the `attachments()` endpoint when needed. Better long-term shape, especially for list views.

If your consumer only needed the existence of attachments (e.g. "show a paperclip icon when files are present"), no migration is needed — checking `@self.files.length > 0` works on both shapes.

## Related references

- OpenRegister change spec: [`openspec/changes/opt-in-files-extend`](https://github.com/ConductionNL/openregister/tree/main/openspec/changes/opt-in-files-extend) — the underlying contract this document inherits.
- OpenRegister API docs: `docs/api/objects.md` in the OpenRegister repo — describes the `_extend` mechanism in full.
- OpenCatalogi `PublicationsController::attachments()` — the recommended path for full single-publication attachment metadata.
