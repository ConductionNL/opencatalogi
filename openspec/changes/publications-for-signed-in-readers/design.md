# Design: publications-for-signed-in-readers

Read at opencatalogi development `0d89f9dd5`. Boards on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a): `OcPublicatie` and `OcPublicatieBewerken`. Both carry a Visibility card: state ("Scheduled"), "Publication date", "Depublication date", and on the detail "Publish now" and "Archive". The new field sits in that card, under the dates, labelled "Who can read it". The boards do not show it yet; the label and place follow the card.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| publication schema | `lib/Settings/publication_register.json`, schema `publication`, `authorization.read` | `group: public` with a `match` on the publication dates |
| public reads | `lib/Controller/PublicationsController.php`, `lib/Service/PublicationQueryService.php::isObjectPublic()` | anonymous, RBAC on |
| public surfaces | sitemaps (`SitemapService`), DCAT (`DcatService`), OOAPI, federation (`FederationController`) | read through the public rule |
| consumer keys | `integration-feed-consumer-credentials` | per catalogue, read protected feeds |

## D1. Two properties

`audience`: enum `public`, `signedIn`, default `public`, title "Who can read it". `minimumAssurance`: enum `substantial`, `high`, default `substantial`, title "Minimum login level", only meaningful for `signedIn`. A migration sets `audience: public` on every existing publication before the read rule changes, so no publication drops off the public side.

## D2. The public rule matches only `public`

The `group: public` rule's `match` gains `audience: "public"`. Every public surface reads through that rule, so they all leave a sign-in publication out. `isObjectPublic()` gains the same check, because search applies it after scoring. A test per surface proves the exclusion; the rule alone is not trusted.

## D3. A portal reads for a signed-in reader

A consumer key gets a flag `actForSignedInReaders`, set by an administrator. A request to `GET /api/{catalogSlug}/{id}` with that key in `Authorization` and a header `X-Reader-Assurance: substantial|high` is served a `signedIn` publication when the key covers the catalogue and the level is at least the publication's minimum. The read runs through `ObjectService` with RBAC off for that one object, after the check. Each such read writes an audit entry: key id, publication id, level, time. Without the key, without the flag, or with a lower level, the answer is 404, the same as for a missing publication.

## D4. Woo stays public

`PublicationValidationService` refuses a save with `audience: signedIn` and a `wooCategory`, with the message "A Woo publication is public by law. Remove the Woo category or make it readable for everyone."

## D5. The editor

The Visibility card on `OcPublicatie` shows "Who can read it: Everyone" or "Readers who log in with DigiD or eHerkenning, level Substantial". In `OcPublicatieBewerken` it is a radio pair with the level as an `NcSelect` that has `inputLabel`. The public link line reads "The link works for readers who log in" for a sign-in publication.

## D6. portaliq

portaliq sends the key and the reader's level from its session claims when a logged-in reader opens a publication, and shows a login prompt when opencatalogi answers 404 to an anonymous request for a link that is marked sign-in in the portal's own page. Two tasks in portaliq; no change to its broker.

## Risks

- A forged assurance header. Only a key with `actForSignedInReaders` is believed, and the administrator grants that per portal. The audit trail shows every read.
- Caches. A sign-in answer carries `Cache-Control: private, no-store`, so no shared cache serves it to an anonymous reader.
