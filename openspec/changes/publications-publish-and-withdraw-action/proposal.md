---
kind: code
depends_on: []
---

# Proposal: publications-publish-and-withdraw-action

## Why

An editor cannot publish or take back a publication in one step. Publishing means typing a date into a field. The only way down is Archive, which is final. The server already has a depublication action that records who took it down, why, and which channels still hold it, but no screen calls it. A single document published by mistake can only come down through OpenRegister's file API.

This change closes two rows.

- opencatalogi matrix, row `pub-status`, "Publish or withdraw a publication with one status change." Own rating partial. Own evidence: "src/manifest.json:737 PublicationDetail lifecycleActions {field: status} -> nextcloud-vue CnDetailPage.vue:163 CnLifecycleActions; publication_register.json:218 only transition archive (published->archived, final); PublicationQueryService.php:453 hides archived publicly; publishing itself = setting publicationDate (PublicationQueryService.php:1008-1040)". Note: "Withdrawing is one action (Archive) but cannot be undone. Publishing is a date field, not a status change. DepublicationController (/api/publications/depublish) and PublishPublicationDialog have no mounted caller."
- filinq matrix, row `woo-withdraw`, "Withdraw a document that was published by mistake." Owned by opencatalogi (`built.owner` ConductionNL/opencatalogi). Demand: tender, TenderNed 407973 (https://www.tenderned.nl/aankondigingen/overzicht/407973). Filinq's note: "OpenCatalogi can depublish what it holds (attachments from its own object view); the gap is filinq's hand-off, the same gap as woo-publish."

What the competitors show on `pub-status`, quoted from the opencatalogi matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "the dataset edit form has an Unpublish button that moves an active dataset back to draft (ckan/templates/package/snippets/package_form.html:47-50, CHANGELOG.rst v.2.12.0 #8308 'Saving a draft dataset is now called Publish'), and the Visibility select switches between private and public in one save" |
| DKAN | yes | "the DKAN publishing workflow (modules/dkan_metastore/config/install/workflows.workflow.dkan_publishing.yml:14-85, states published, draft, archived, hidden; transitions publish, archive, restore) is set on the edit form moderation select and via PUT /api/1/metastore/schemas/{schema_id}/items/{id}/publish" |

`pub-status` is `build` under "two or more competitors rated yes"; `woo-withdraw` under "a tender demand row". They share one screen and one service, so they are one change.

## What changes

- The publication page's Actions menu offers Publish now, Withdraw and Publish again, each shown only when it applies.
- Withdraw asks for a reason, takes the publication down at once, records the depublication, and sends a withdrawal to every national channel the publication reached.
- A withdrawn publication can be published again. Archive stays the final retention step.
- Each document in the Attachments section can be withdrawn on its own, with a reason, without taking the whole publication down.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `pub-status` | Publish or withdraw a publication with one status change. | partial | publishing is a date field, withdrawing is final, the depublish action has no screen |
| filinq | `woo-withdraw` | Withdraw a document that was published by mistake. | no | opencatalogi's half: no screen withdraws one document with a reason |

## Existing work it builds on

- Open change `publication-inspection-and-the-national-indexes` shipped `DepublicationController::depublish()` and `DepublicationService` (REQ-PIN-106) with every task checked. No screen calls them, and the action does not change the publication's own `depublicationDate` (row `lc-depublish`: "The publication's own depublicationDate or status is not changed by this action"). This change adds the screen and that write.
- Main spec `publications`, PUB-016 to PUB-018: the store's publish and depublish calls and the `PublishPublicationDialog`, whose confirm handler copies a menu instead of publishing (the spec's own note). This change does not revive that dialog; the page's header actions replace it.
- Open change `attachments-are-files` and OpenRegister's file publication window: a file has its own `published` and `depublished` time, so one document can come down alone.

## Out of scope

- The acknowledgement screen for channel withdrawals (row `lc-depublish`, deferred). The outstanding channels are shown after withdrawing; recording each acknowledgement stays an API call.
- filinq's hand-off of documents to OpenCatalogi, which stays in filinq's change `woo-publicatie-pipeline`.

## Sibling halves

- ConductionNL/filinq owes the hand-off (`woo-publicatie-pipeline`). Once a filinq document is a file on an OpenCatalogi publication, this change's document withdrawal applies to it unchanged.
- ConductionNL/integriq owes the national channels' `withdrawals` endpoint that `NationalIndexService::withdraw()` calls. Without it, a withdrawal is recorded as not delivered, which is what the service already does.
