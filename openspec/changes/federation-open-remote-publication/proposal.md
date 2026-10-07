---
kind: code
depends_on: []
---

# Proposal: federation-open-remote-publication

## Why

The federated search finds publications of other organisations, but opening one throws the reader out of the app. The result opens in a new tab on the other instance, through a deep link that probably lands on that instance's dashboard, and its attachments cannot be listed from here at all.

opencatalogi matrix, row `fed-open-remote`, "Open a publication from another organisation with its attachments without leaving the app." Own rating partial.

- Own evidence: "FederationSearch.vue:224-268 opens federated results with window.open in a new tab on the peer; PublicationService.php:2683 getFederatedPublication falls back to DirectoryService getPublication for metadata; PublicationService.php:917 attachments() is local-only (catalog-scope gate returns 404 for remote ids)".
- Note: "Remote attachments cannot be opened through this app. The peer deep link uses '#/publications/...' (FederationSearch.vue:266) while the app routes are history mode (routes.php:262), so it probably lands on the peer's dashboard."

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "via ckanext-harvest v1.6.2: a harvested dataset from another portal is stored locally with its resources ... and opens on the normal /dataset/<name> page with its resource list and previews ... Downloads go to the remote file URL." |
| DKAN | yes | "a harvested dataset is a local copy that opens on the local dataset page with its distributions (modules/dkan_harvest/src/Load/Dataset.php:54-57, modules/dkan_metastore/templates/node--data.html.twig:111-117)" |

No demand row. The row is `build` under the rule "two or more competitors rated yes".

## What changes

- A federated search result opens on a page inside the app that shows the remote publication's metadata, its organisation and its attachments.
- The attachments list comes from the other instance at the moment of reading. Downloads go straight to the other instance's file URL; nothing is copied.
- The page says which organisation and instance the publication comes from, and offers a correct link to open it there.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `fed-open-remote` | Open a publication from another organisation with its attachments without leaving the app. | partial | a remote result leaves the app; remote attachments cannot be listed; the peer link uses a hash route |

## Existing work it builds on

- Main spec `federation`, FED-002 (a single publication from local or federated sources) and FED-005 (attachments from local or federated sources). FED-005 says federated, but `FederationController::publicationAttachments()` only reads local attachments. This change makes the federated half real.
- `DirectoryService::getPublication()` and its outbound URL guard (ADR-054), reused for the attachments call.

## Out of scope

- Harvesting remote publications into local copies, which is what CKAN and DKAN do. The federation model here reads the peer live; a copy belongs to harvesting, which runs on OpenRegister (`openregister/app-harvest-fetchers-and-flow-node`): another OpenCatalogi's DCAT feed can be registered as a harvest feed of type `opencatalogi.dcat-jsonld` (`harvest-feed-intake`), and its datasets arrive as draft publications with their source.
- Proxying remote file downloads through this instance.
