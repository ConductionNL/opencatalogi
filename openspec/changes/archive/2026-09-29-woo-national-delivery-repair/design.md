# Design: woo-national-delivery-repair

## The defect

`NationalIndexService::handOver()` passes `$channel` (a string) as `source:`. `OCA\Integriq\Service\CallService::call()` declares `ObjectEntity $source`. Nothing in the unit tests uses the real class, which is how it shipped.

## Channel to source

New app config key `channel_sources`, a JSON map `{ "national-woo-index": "<source slug>", "national-publication-platform": "<source slug>", "plooi": "<source slug>" }`, edited in `src/views/settings/Settings.vue` next to the registration status. The settings save keeps only these three channels and only non-empty slugs.

Changed at build time (29 Sep): the map holds source SLUGS, not uuids, and `handOver()` resolves them through integriq's own `ConnectionStore::findSourceBySlug()`, not through OpenRegister's `ObjectService`. Two reasons read from integriq `development`: its gateway transport (`SourceGatewayTransport`) addresses a source by slug through `config.source`, so one value has to serve both the direct call and the `publicatie` gateway; and integriq's `source` schema is admin-only, so a lookup from this app under the editor's own rights would find nothing, while `ConnectionStore` reads as OpenRegister's system principal. The resolved `ObjectEntity` is what `CallService::call()` gets. Its return is the call log, so the answer is read from `getObject()['response']`, and a status of 300 or more is a failure, never an acknowledgement. The class names are tried under both app ids, as `GATEWAY_SERVICES` already was.

## Official notices

`deliver()` for `CHANNEL_NATIONAL` builds `{reference: {app: 'opencatalogi', id, url}, instruction: {publicationType, effectiveDate}}` and dispatches `GatewayDeliveryRequestedEvent(gatewayId: 'publicatie', request, sourceApp: 'opencatalogi', config: [source])`. A `null` delivery means integriq did not take the request, and the result is an `IndexUnreachableException`, never an acknowledgement. A refusal code (`unknown-gateway`, `invalid-request`) is returned to the editor as is.

## PLOOI

A listener on the publication update event, built on the real `ObjectUpdatedEvent`, queues a `PlooiDelivery` background job when `publicationDate` has just become at most now (and no `depublicationDate` has passed). It reads and writes nothing else on the save path (ADR-078). The job asks OpenRegister for catalogues with `plooiDelivery` on whose `registers` and `schemas` hold the publication; with none, it does nothing. With one, it posts the DiWoo metadata (identifier, title, description, publication date, organisation) and the document links from the publication's files to the PLOOI source, and stores `plooiStatus` (delivered or failed), `plooiDeliveredAt`, `plooiIdentifier` and `plooiReason` on the publication with a silent save, so the write does not trigger the listener again.

Changed at build time: `plooiReason` is a fourth property, because the spec asks for a failure stored "with the reason" and the three planned fields had nowhere to hold it. The body is built in `PlooiDeliveryService::document()`, not by reusing `SitemapService::mapDiwooDocument()`, which is private and maps one file per sitemap entry rather than one publication.

## Announce screen

Changed at build time: the publication page is manifest-driven (`PublicationDetail` in `src/manifest.json`), so the Announce action is a detail-page widget, `national-announce`, placed under the attachments. It shows only to an administrator, because the endpoint is admin-only. It asks for the publication type and the effective date the `publicatie` gateway requires, since a publication record carries neither, and lists every channel with its result, including the ones not reached.

## Risks

The exact PLOOI request shape must be read from the delivery API description at build time; the design fixes the wiring and the stored fields, not the field-by-field body.
