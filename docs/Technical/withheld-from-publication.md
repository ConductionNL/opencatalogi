# Withheld from publication: the public `withheld` list

A catalogue can show, on a public publication, which of its documents were withheld from publication and on which grounds. It never shows their content.

## Switching it on

Set `showWithheld` to true on the catalogue (the catalogue form has a toggle). It is off by default. On a document assessment of a publication batch, `titlePublic` (off by default) lets that document's title show too.

## Where it appears

- `GET /api/{catalogSlug}/{id}` (`publications#show`)
- `GET /api/federation/publications/{id}` (`federation#publication`)

Both answer the same list. The federation route has no catalogue in its path, so the catalogue is found from the publication's register and schema: the list is there only when every catalogue that holds the publication has `showWithheld` on.

## The shape

```json
"withheld": [
  {
    "position": 3,
    "grounds": ["5.1.2.e"],
    "groundDetails": [
      { "code": "5.1.2.e", "article": "Artikel 5.1, tweede lid, onder e", "label": "Eerbiediging van de persoonlijke levenssfeer" }
    ]
  }
]
```

- `position`: the document's place in the publication, from 1. Entries are ordered by it.
- `grounds`: the refusal ground codes.
- `groundDetails`: `{code, article, label}` per code. For a record an app wrote (dossiq records them through `WithheldDocuments::record()`), these are the values stored when it was recorded. For an entry from a publication batch, `article` and `label` are empty until the grounds list is read through `RefusalGrounds`; a code is never given a guessed label.
- `title`: only on a batch entry whose assessment has `titlePublic` on.

## When the key is missing

`withheld` is left out, never sent empty, when the catalogue did not opt in, when the catalogue cannot be found, when the publication is not public, or when the list cannot be read. An empty list would say nothing was withheld.

A portal that renders this (portaliq `publication-error-reports-and-withheld-notices`) should show the `groundDetails` labels and test against these keys. The schema is `PublicPublication` and `WithheldEntry` in `openapi.json`.
