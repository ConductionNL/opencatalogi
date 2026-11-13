# Document references: publishing a document by its URL

A document often already lives in another system: a document management
system, the council information system, a case system. A document reference
publishes it from there. The editor gives its public https address, a title
and a format; OpenCatalogi never downloads or stores the file.

## Adding a reference

On a publication's page, the **Document references** list shows the
publication's references. Add one with:

| Field | What to fill in |
|---|---|
| URL | The public `https://` address of the document. |
| Title | The title readers see. |
| Format | The file type as an extension, such as `pdf` or `docx`. It becomes `diwoo:format` and the DCAT media type. |
| Source system | Optional: the name of the system the document lives in. |
| Publication date / Depublication date | The window in which the reference is public, like a file's. |

An address that is not public is refused when you save, with the reason:
`http://` addresses, `localhost`, and hosts that point at a loopback, private
or link-local network. Readers could not open those.

## Where a reference appears

Inside its window, a reference is published like a file, with the source URL
as its location:

- in the DiWoo category sitemap, as a document whose `loc` is the URL;
- in the DCAT feed, as a distribution whose download and access URL is the URL;
- in the public attachments list, `GET /api/{catalogSlug}/{id}/attachments`,
  after the files, with `kind: reference`.

Outside its window it appears in none of them.

## Broken references

Once a day the app asks each source system whether the document still answers:
a `HEAD` request, and a ranged `GET` of one byte when the server refuses
`HEAD`. It records **Last checked**, **Reachable** and **Last status** (the
HTTP status, or 0 when nothing answered). One run checks at most 500
references.

A reference that did not answer shows as not reachable in the list on the
publication page, and the DiWoo validator report lists it under
`unreachableReferences` with its status and check time. It stays in the
sitemap: the harvester decides what to do with it. Fixing the address is the
editor's work; the check only makes it visible.
