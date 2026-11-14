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

## Saving a featured subject

A subject saved with `featured: true` and no `title` is refused.
The error names the property: `{"message": "A featured subject needs a title", "property": "title"}`.
