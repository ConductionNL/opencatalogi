# Subjects API for the portal

Subjects are the objects of the `theme` schema in the publication register.
These routes are public. They answer with the same CORS headers as the other theme routes.

## Featured subjects

`GET /index.php/apps/opencatalogi/api/themes?featured=true`

```json
{
  "results": [
    {
      "id": "uuid",
      "slug": "parkeren",
      "title": "Parkeren",
      "summary": "string",
      "description": "string",
      "image": "string",
      "url": "string",
      "isExternal": false,
      "featuredOrder": 1,
      "publicationCount": 2
    }
  ],
  "total": 1
}
```

- Only subjects with `featured: true` are listed.
- They come ordered by `featuredOrder`, then by `title`.
- `publicationCount` counts the publications an anonymous reader may see under the subject.
- A draft, scheduled or withdrawn publication is never counted, whoever calls the route.
- A key the subject does not hold is `null`.

## One subject

`GET /index.php/apps/opencatalogi/api/themes/{id}` answers the subject as before, plus two keys:

- `publicationsUrl`: the path of the list below.
- `publicationCount`: the count described above.

## What is filed under a subject

`GET /index.php/apps/opencatalogi/api/themes/{id}/publications?_limit=20&_page=1`

`{id}` is the subject's id, uuid or slug. An unknown subject answers 404.

```json
{
  "results": [],
  "total": 0,
  "limit": 20,
  "offset": 0,
  "page": 1,
  "pages": 1
}
```

- The publications come newest first, by `publicationDate`.
- The list is read as an anonymous caller, so it equals what the public sees.
- `_limit` is at most 100.

## Subjects in the public search

`GET /index.php/apps/opencatalogi/api/search?_search=sportaccommodatie`

The search also matches subjects on the term. Every result carries `resultType`:

- `publication`: a publication, in the shape the search has always answered.
- `document`: a document hit, with its linked publication.
- `subject`: a subject, in this shape:

```json
{
  "resultType": "subject",
  "id": "uuid",
  "slug": "sporthal",
  "title": "Sporthal",
  "summary": "string",
  "image": "string",
  "publicationCount": 2,
  "url": "/index.php/apps/opencatalogi/api/themes/uuid"
}
```

- Subjects come after the publications and documents of the page, at most 20 of them. `total` includes them.
- `facets.resultType` counts the results of this answer per type: `{"publication": 1, "document": 0, "subject": 1}`.
- `resultType=publication` answers exactly what the search answered before subjects existed, with `resultType` on each row. No subject is read.
- `resultType=subject` answers the subjects alone.
- Without a `_search` term no subject is listed.
- The subjects are read as an anonymous caller, like the rest of the search.

A portal that reads these keys tests against them in its own suite.

## Saving a featured subject

A subject saved with `featured: true` and no `title` is refused.
The error names the property: `{"message": "A featured subject needs a title", "property": "title"}`.
