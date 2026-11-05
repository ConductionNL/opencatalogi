# Publication slugs: a stable public URL

A publication can be fetched by a slug instead of its UUID. A UUID is assigned by the
instance that stores the object, so a re-import, a re-provision or a reset of a
synchronisation gives the same publication a new UUID and breaks every link that was
shared with the old one. A slug is derived from a value in the publication itself, so
the same source record gets the same slug on every instance.

## Where the slug comes from

The slug is OpenRegister's object slug (`@self.slug`). It is switched on per schema with
`configuration.objectSlugField`, the property whose value is slugified. OpenRegister
generates the slug at the first save, keeps it unique within the register and schema, and
never changes it afterwards: a later edit of the source property, or of the title, leaves
the URL as it is. How generation, normalisation, uniqueness and the backfill of existing
objects work is described in the OpenRegister documentation, section "Object slugs" of
the schema configuration page.

For the Woo publication types the slug source is `kenmerk`, the case number from the
source system; that configuration ships with the Woo configuration, not with this app.
A schema without `objectSlugField` gets no slugs and its publications stay reachable by
UUID only. The `publication` schema this app seeds configures none.

Existing publications get a slug with the OpenRegister backfill command:

```bash
occ openregister:backfill-slugs
```

## The public lookup

`GET /api/{catalogSlug}/{id}` accepts a UUID or a slug as `{id}`.

1. The identifier is first resolved as a UUID within the registers and schemas of the
   catalogue, exactly as before. A UUID link keeps working and costs nothing extra.
2. Only when no publication matches is it resolved as a slug, again within the
   registers and schemas of the catalogue. A publication in another catalogue is never
   returned, also when its slug matches.

Before the slug lookup the identifier is normalised the way OpenRegister normalises a
stored slug: lower case, spaces and underscores become hyphens, every other character
outside `a-z`, `0-9` and `-` is dropped, runs of hyphens collapse, edge hyphens are
trimmed, at most 255 characters. `/api/woo/Zaak_2026-0042` finds the publication stored
as `zaak-2026-0042`. An identifier with nothing left after normalising runs no slug
search and answers 404.

The answer has the same shape as the answer to a UUID lookup.

## When a slug is not unique in the catalogue

OpenRegister keeps a slug unique per register and schema. A catalogue spans several
registers and schemas, so the same slug can occur once per schema inside one catalogue.
Such a slug answers 404 with the usual "Publication not found" body, not 409: the
endpoint is anonymous, and a 409 would tell any caller that two publications share a
case number, which a visitor cannot act on. A warning in `nextcloud.log` names the slug,
the catalogue and the UUIDs of the publications involved, so the administrator can
resolve it.

| request | answer |
|---|---|
| UUID of a publication in the catalogue | 200, the publication |
| slug held by one publication in the catalogue | 200, the publication |
| slug held by a publication in another catalogue only | 404 |
| slug held by two publications in the catalogue | 404, warning in the log |
| unknown slug, or an identifier that normalises to nothing | 404 |

## What is not claimed

The sitemaps, the schema.org output and the federation and directory URLs still carry
the UUID. The Woo website template chooses the slug URL when a publication has a slug
and falls back to the UUID URL otherwise; that is the template's rule, not this app's.
There is no redirect from a UUID URL to a slug URL. The sub-routes of a publication
(`/attachments`, `/uses`, `/used`, the file downloads) are documented for the UUID; what
they do with a slug is not claimed here.
