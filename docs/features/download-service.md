# Publication download

A reader can download a publication as one ZIP file: the publication's metadata as a
PDF, plus every attachment the reader may see. It is meant for archiving, a Woo export,
or a citizen who wants the whole publication offline.

## Endpoints

| Route | Who |
| --- | --- |
| `GET /api/{catalogSlug}/{id}/download` | Public; the publication must be in that catalog |
| `GET /api/search/{id}/download` | Public; the publication must be in one of this instance's catalogs |
| `GET /api/federation/publications/{id}/download` | A publication of another, federated catalog |

Each route also answers an `OPTIONS` preflight with the CORS headers.

## What the ZIP holds

- `<title>.pdf` at the root: the publication's metadata, rendered from the
  `publication.html.twig` template with mPDF.
- `Bijlagen/`: the publication's files. OpenRegister builds the archive of the object's
  files (`createObjectFilesZip()`); OpenCatalogi then moves them into `Bijlagen/` and adds
  the PDF. An anonymous reader gets only the files shared publicly; a signed-in reader
  gets all of the publication's files.

The PDF is made on demand and never stored: it is written to a temporary file, read
back, and the file is removed straight away, also when rendering fails. OpenCatalogi does
not save the download, or a share link to it, in anyone's Nextcloud files. An earlier
version had a "save to Nextcloud" option; it was removed so file storage stays with
OpenRegister.

## Answers

| Status | When |
| --- | --- |
| 200 | The ZIP file |
| 404 | The publication does not exist or is outside the catalog |
| 500 | The archive or the PDF could not be made; the message says which part failed |

Error messages are translated into the caller's language. A successful public download
counts once in the publication's usage figures; a failed one does not.
