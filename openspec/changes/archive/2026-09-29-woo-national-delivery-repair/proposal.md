---
kind: code
depends_on: [woo-index-harvester-connection]
---

# Proposal: woo-national-delivery-repair

## Why

Every hand-over from OpenCatalogi to a national channel fails. `NationalIndexService::handOver()` (`lib/Service/Publication/NationalIndexService.php`) calls the integriq gateway as `call(source: <channel name string>, ...)`, but integriq's `CallService::call()` takes `ObjectEntity $source` (`integriq lib/Service/CallService.php:3033`). The call throws a TypeError, the service catches it and reports the channel as unreachable. `registerWithWooIndex()` has no caller, `PublicationDisclosureController::announce` reaches the same failing code, and no code mentions PLOOI, the delivery API of open.overheid.nl.

Rows, opencatalogi matrix:

| row id | name | own rating | state |
|---|---|---|---|
| `woo-plooi` | Deliver publications to open.overheid.nl through its delivery API rather than by harvest. | no | building |
| `dec-announce` | Compose an official notice and send it to the national publication platform and the local channel. | no | building |

Decision. `woo-plooi` is in the core Woo area, so it is `build`. `dec-announce` has no competitor rated yes and is outside the core area, so on its own it would be `decided-no`. It joins this change because the same defect, in the same lines, blocks it, and the repair adds no scope for it. Recorded in `gap-decisions.json` so it can be reversed.

## What is already built, and what is not

Built: notice composition (`composeNotice`), the channel constants, the `IndexUnreachableException` path that refuses to call an empty answer acknowledged, the `announce` endpoint and the withdrawal hand-over used by depublication.

Not built: a gateway call that type-checks, a channel-to-source setting, a PLOOI payload, and any caller of `registerWithWooIndex()`.

## What changes

- Each national channel gets a source in integriq, chosen in the Woo section of the admin settings. `handOver()` resolves that source to the object integriq expects and calls it. A channel with no source fails at once with a message that names the channel and the missing setting.
- Official notices for the national publication platform go through integriq's `publicatie` gateway by sending `GatewayDeliveryRequestedEvent`, so the document travels by reference, as that gateway requires.
- A new delivery to PLOOI: when a publication becomes public in a catalogue that has the PLOOI channel on, its DiWoo metadata and document links are posted to the PLOOI source, and the answer is stored on the publication as delivery status, time and platform identifier.
- The announce endpoint gets a caller: the Announce action on the publication page.

## Out of scope

The PLOOI source itself (URL, credentials, mTLS) is created by the administrator in integriq; no code is needed there. Harvest through sitemaps stays the default route and is not changed.
