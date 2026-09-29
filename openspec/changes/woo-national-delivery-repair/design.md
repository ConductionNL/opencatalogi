# Design: woo-national-delivery-repair

## The defect

`NationalIndexService::handOver()` passes `$channel` (a string) as `source:`. `OCA\Integriq\Service\CallService::call()` declares `ObjectEntity $source`. Nothing in the unit tests uses the real class, which is how it shipped.

## Channel to source

New app config key `channel_sources`, a JSON map `{ "national-woo-index": "<source uuid>", "national-publication-platform": "<source uuid>", "plooi": "<source uuid>" }`, edited in `src/views/settings/Settings.vue` next to the registration status. `handOver()` reads the uuid, loads the source object through OpenRegister's `ObjectService` in the integriq register, and passes that `ObjectEntity` to `call()`. The gateway class name list `GATEWAY_SERVICES` stays, because the app id of integriq still moves; the event class is looked up by the same two names.

## Official notices

`deliver()` for `CHANNEL_NATIONAL` builds `{reference: {app: 'opencatalogi', id, url}, instruction: {publicationType, effectiveDate}}` and dispatches `GatewayDeliveryRequestedEvent(gatewayId: 'publicatie', request, sourceApp: 'opencatalogi', config: [source])`. A `null` delivery means integriq did not take the request, and the result is an `IndexUnreachableException`, never an acknowledgement. A refusal code (`unknown-gateway`, `invalid-request`) is returned to the editor as is.

## PLOOI

A listener on the publication update event (built the same way as the readiness trigger in `woo-index-harvester-connection`, on the real `ObjectUpdatedEvent`) delivers when `publicationDate` has just become at most now and the catalogue has `plooiDelivery` on. The body reuses `SitemapService::mapDiwooDocument()`. Stored on the publication: `plooiStatus`, `plooiDeliveredAt`, `plooiIdentifier`, added as optional properties.

## Risks

The exact PLOOI request shape must be read from the delivery API description at build time; the design fixes the wiring and the stored fields, not the field-by-field body.
