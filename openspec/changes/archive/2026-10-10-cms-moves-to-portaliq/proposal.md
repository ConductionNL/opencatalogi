# The CMS moves to Portaliq

## Why

OpenCatalogi and Portaliq both declared `page` and `menu`, and a schema slug is
global per organisation, so the two collided.

Measuring them showed they are not two copies of one model. `page` shares ONE
property of eleven:

| | opencatalogi | portaliq |
| --- | --- | --- |
| shared | `title` | `title` |
| only here | `contents`, `groups`, `hideBeforeLogin`, `hideAfterLogin`, `slug` | `body`, `draftBody`, `locale`, `portal`, `route`, `status`, `summary` |

`menu` overlaps more: `items`, `position`, `title` of six.

Portaliq is where a portal's content belongs, and it already serves
`/api/content/pages` and `/api/content/menus` publicly. So the content moves
there, rather than either app learning about the other.

This change ships the MIGRATION. Removing the schemas, the API and the CMS UI
from opencatalogi follows it, because the data has to move first.

## What the mapping does, and what it refuses

`slug` becomes an in-portal `route` with a leading slash. A page with no slug
becomes the portal root, which is what it already behaved as.

`contents` is an ARRAY of `{type, data}` blocks; Portaliq's `body` is an OBJECT
carrying a 12-column widget grid. Blocks stack down that grid in the order they
were authored, which is how they rendered before.

The block-to-widget map is DECLARED, not passed through:

- `hero` → `hero`, props unchanged.
- `text` → `markdown`, and its `content` is rewritten to `markdown`. Passing the
  block through would save cleanly and render an EMPTY widget, because that is
  the key Portaliq's widget reads.

**An unknown block type is refused, not guessed.** A guessed widget key produces
a page that saves, renders nothing, and reports no error.

**A `--portal` is required.** A Portaliq page and menu must name a portal, and
nothing in the source data says which one. Refusing is better than defaulting to
whichever portal happens to be first.

Every migrated page is `published`, because opencatalogi had no draft state, so
every source page was live and importing them as drafts would take a working
site offline.

## What cannot be carried, and is said out loud

Portaliq's hero declares no `subtitle`, and its menu declares no `groups`,
`hideBeforeLogin` or `icon`. Those are carried in props so the text is not
destroyed, and REPORTED, so nobody discovers them missing from a rendered page
instead.
