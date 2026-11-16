# Publication detail for the portal: the public keys

The citizen's publication page in portaliq reads these keys from OpenCatalogi's public endpoints. Portaliq tests against them: `portaliq/publication-detail-page-complete` reads REQ-PDP-001 to REQ-PDP-003, and `portaliq/publication-error-reports-and-withheld-notices` reads REQ-PDP-004 and REQ-PDP-005. A change to a key on this page is a change to that contract.

The machine-readable shapes are in `openapi.json` (catalogue routes) and `openapi.standards.json` (federation routes): `AttachmentPage`, `DocumentFacts`, `PublicDocument`, `CommentPeriodView`, `PublicPublication`, `WithheldEntry`, `ErrorReportRequest` and `ErrorReportReceipt`.

## Document order (REQ-PDP-001)

`GET /api/{catalogSlug}/{id}/attachments` and `GET /api/federation/publications/{id}/attachments` answer the documents in the order an officer stored in the publication's `attachmentOrder`, then any other file by upload time. Each result carries `position`, from 1, counted across pages.

## One document (REQ-PDP-002)

Each result on both attachment lists carries:

| key | meaning |
|---|---|
| `documentId` | The Nextcloud file id, as a string. A rename keeps it. |
| `title`, `size`, `published`, `downloadUrl` | As stored on the file. |
| `format` | The extension in lower case, else the media type. |
| `documentsoort` | Only when stored: the file's own type, else the publication's. |
| `creationDate` | Only when the publication stores one. |
| `metadataUrl`, `diwooUrl` | The two routes below. Absent when no catalogue holds the publication. |

`GET /api/{catalogSlug}/{id}/documents/{documentId}` answers the keys above, `position`, and `publication: {id, title, url}`. `GET /api/{catalogSlug}/{id}/documents/{documentId}/diwoo` answers one `diwoo:Document` as XML, as the DiWoo sitemap renders it. Both answer 404 for a draft, a depublished publication, a withdrawn file, a file that is not shared, and a file of another publication. Both are public, CORS enabled and read inside OpenRegister's anonymous scope.

## Comment period (REQ-PDP-003)

`GET /api/{catalogSlug}/{id}` and `GET /api/federation/publications/{id}` carry `commentPeriod` when the publication has one:

```json
"commentPeriod": {
  "state": "open",
  "startDate": "2026-11-10T00:00:00Z",
  "endDate": "2026-11-24T00:00:00Z",
  "termDays": 14,
  "legalRemedy": "zienswijze",
  "reactionFormUrl": "https://example.nl/reageer"
}
```

`state` is `upcoming`, `open` or `closed`. `reactionFormUrl` is null unless the period is open. When the dates cannot be read the response carries `"commentPeriodError": "unreadable-dates"` and no `commentPeriod`. No state is guessed.

## Withheld documents (REQ-PDP-004)

Only when the catalogue has `showWithheld` on, the publication carries `withheld`. See [Withheld from publication](withheld-from-publication.md) for the shape. Without the opt-in the key is absent.

## Error reports (REQ-PDP-005)

`POST /api/publications/{id}/error-reports` with `{"message": "...", "contact": "optional@example.nl"}`:

| answer | when |
|---|---|
| `201 {"reference": "<uuid>"}` | The report is stored with status `new`. |
| `400 {"error": "message-required" \| "message-too-long" \| "invalid-contact"}` | The message is empty or over 2,000 characters, or the contact is not an e-mail address. |
| `404` | The publication is not published, or its catalogue does not have `acceptErrorReports` on with at least one group in `errorReportModerators`. |
| `429` | More than five reports in an hour from one address. |
| `503 {"error": "not-configured"}` | The `error_report_schema` setting is empty. |

The report is never published and stores no IP address. Its notification rule reaches the members of the catalogue's moderator groups.
