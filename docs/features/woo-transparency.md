# WOO Transparency

## Overview

WOO (Wet open overheid) compliance features enable government organizations to manage the publication of documents under Dutch open government law. This includes document assessment, redaction workflow, batch processing, and inventarislijst (inventory list) generation.

## Document Queue

The WOO document queue provides a batch processing interface for documents received from case management systems (e.g., Procest). Documents flow through assessment statuses:

- **Ontvangen** (Received) - Document enters the queue
- **Beoordeling** (Assessment) - Document under review
- **Openbaar** (Public) - Document approved for publication
- **Geweigerd** (Refused) - Document refused with grounds

## Weigeringsgronden

When refusing publication, assessors select applicable legal grounds (weigeringsgronden) from the WOO article references. These are stored as structured metadata.

## Batch Processing

Related documents are grouped into WOO batches. Bulk assessment enables processing multiple documents with the same decision simultaneously.

## Reviewing a batch

A batch moves through four states: in progress, ready for review, approved and published. Each move is a declared transition on OpenRegister, so no path skips one.

1. The officer assesses every document and chooses **Mark ready for review**.
2. While the batch is in review, its page shows each document as the public will see it, in place of the document queue. For a public document that is the original file. For a partly public document it is the verified redacted file, never the original. A withheld document shows no file. Next to each file stand the publication fields the public read returns: the title, the information category, the kind of publication, the date and the case reference.
3. The reviewer opens the public view of every published document. **Approve** stays disabled until they have. The server checks the same thing again on every path to approval, so an approval through OpenRegister's task list is refused too until the approving reviewer opened every view.
4. Approving starts the `batchReview` approval chain for the role `woo-reviewer`. When the chain completes, OpenRegister moves the batch to approved. Only an approved batch can be published.

### Rejecting and sending back

**Reject** asks for a reason in its own dialog. The batch goes back to in progress and is assigned to the officer who created it. The reason is kept on the batch with the reviewer and the date, the batch page shows it, and the officer gets a notification naming the batch and the reason. A rejection of the review task in OpenRegister's task list does the same, with the task's comment as the reason. A task rejected without a comment leaves the batch in review.

**Send back** on one document returns only that document to assessment. It needs a reason too, and works only while the batch is in review. The assessment page shows the reason with who sent it back.

## Redaction log

Every partly public document keeps a redaction log. It lists, per redacted passage, the kind of information, where it sits in the text and the legal grounds. The log is filled from OpenRegister's record of what was actually redacted, once the redaction is verified.

The review page and the redaction page show the log. A passage without a ground is marked. Publishing is refused, naming the document, when the log of a partly public document is empty, cannot be read or has a passage without a ground. The Woo requires a ground for every withheld part.

The log is internal. It is stored on the assessment, which only administrators read, and it never appears in a public response.

## Inventarislijst

The inventarislijst is a structured export listing all documents in a batch with their assessment decisions, applicable weigeringsgronden, and publication status.

## Connecting to the Woo-index

The national Woo-index harvests your DiWoo sitemaps. It needs two things from you: a `robots.txt` at the root of your domain that points at them, and a registration.

### robots.txt at the domain root

OpenCatalogi serves the file at `/index.php/apps/opencatalogi/api/robots.txt`. It lists one `Sitemap:` line per category of every catalogue that publishes a Woo sitemap, and allows the API paths.

Nextcloud answers `/robots.txt` at the root itself, with `Disallow: /`. So the harvester never reaches the app's file until you add a web-server rule. The Woo-index section of the OpenCatalogi admin settings shows both rules for your own base URL, each with a copy button:

- **nginx:** a `location = /robots.txt` block that rewrites to the app's route.
- **Apache:** a `RewriteRule` for the Nextcloud `.htaccess` or the virtual host.

When the reading room runs on its own domain, add the rule on that domain. The readiness check reads the root file; when it finds no sitemap line there, it says so and points at these rules.

### Registration

Press **Request registration**. OpenCatalogi composes the request: the organisation's name and TOOI identifier, the root `robots.txt` URL and every sitemap index. It hands the request to integriq's `national-woo-index` channel. The status moves to requested only when the gateway answered, and the answer is kept.

With no gateway installed, nothing is recorded as sent. The panel shows the composed request, so you can send it another way. When the Woo-index confirms, press **Mark as registered**.

### A current verdict

The readiness check runs once a day while a catalogue publishes a Woo sitemap. It also runs once right after a catalogue is switched on for Woo.

## DiWoo metadata on the publication

The national Woo-index only takes a document whose metadata follows the DiWoo metadata standard. A publication stores that metadata in its own fields, and the DiWoo sitemap emits what it stores.

| Field | What it holds |
| --- | --- |
| `title` | The official title (`diwoo:officieleTitel`). |
| `documentsoort` | The document type, from the national list of document types. Stored as the address of the list member. |
| `fileDocumentsoorten` | A different document type for one attached file, such as an annex to a decision. Keyed by the file id. |
| `language` | The language of the document. It defaults to `nl`. |
| `creationDate` | The day the document was made. This is not the day it was published. |
| `validFrom` and `validUntil` | The days the content takes and loses legal effect. They are apart from the publication date. |

`GET /api/woo/documentsoorten` lists the document types an editor can choose from.

### What a save checks

A document type may be given as its code, its label or its address. The save stores the address. A document type the national list does not hold is refused. A validity that ends before it starts is refused.

A Woo publication cannot be made public while a field the Woo-index requires is missing. This holds for a publication date now and for one in the future. A Woo publication has a Woo category, or sits in a schema a category names. The required fields are the publisher with a TOOI identifier, the title, the information category and the handling type. The refusal names every missing field. A draft without a publication date may be incomplete.

Publishing a Woo batch reports the same refusal and leaves the batch unpublished.

### What the sitemap emits

Each document carries its title, document type, language, creation date, validity and the responsible organisation. Until the responsible organisation has its own field, it is the publisher.

A publication created before these fields existed falls back to the old derivation: the day it was saved for the creation date, and `nl` for the language. A newer publication gets no fallback. A document that still lacks a required field is left off the sitemap page, and the rest of the page is served. The DiWoo validation report at `/api/{catalogSlug}/sitemaps/{categoryCode}/validate` lists each held-back document with the field and the reason.

The national announcement takes `validFrom` as its effective date when the editor gives none.

### Release notes

- An earlier hand-off wrote a document type into the summary of a publication. On upgrade, a summary that is exactly a document type moves to the document type field, and that summary is emptied. Editors may want to write a real summary for those publications.
- A Woo publication can no longer be made public without the metadata the Woo-index requires. The save names what is missing.
