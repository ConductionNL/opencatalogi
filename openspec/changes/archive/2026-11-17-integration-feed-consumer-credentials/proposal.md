---
kind: code
depends_on: []
---

# Proposal: integration-feed-consumer-credentials

## Why

A protected feed, such as a catalogue's OOAPI feed for an education partner, is read today with any Nextcloud account and its app password. One instance-wide list says which accounts may read, it covers every catalogue at once, and no screen shows it. An administrator cannot give one partner a key for one catalogue, see when it was used, or replace it without breaking the partner.

opencatalogi matrix, row `int-consumer-keys`, "Give an outside consumer its own credentials for a protected feed." Own rating partial.

- Own evidence: "lib/Controller/OoapiController.php:195 requireAuthenticatedConsumer on every OOAPI action; lib/Service/OoapiService.php:168 isConsumerAllowed reads an instance-wide ooapi_consumers allowlist (empty means every Nextcloud user); lib/Service/SettingsService.php:514 says per-catalog credential scoping is NOT implemented".
- Note: "Credentials are Nextcloud accounts and app passwords, gated by one instance-wide list. No per-feed key exists, and nothing covers DCAT or other feeds."

What the competitors show, quoted from the matrix:

| system | rating | evidence |
|---|---|---|
| CKAN | yes | "each consumer gets its own user with API tokens created and revoked at /user/<id>/api-tokens (ckan/views/user.py:969-972, template user/api_tokens.html; action ckan/logic/action/create.py:1431 api_token_create), optionally expiring (ckan/ckanext/expire_api_token)" |
| DKAN | yes | "the Alternate API module puts a second set of metastore and SQL read routes behind their own permissions ... and installs an 'Alternate API user' role holding them ..., so each consumer gets its own Drupal account and basic-auth credentials for the protected feed." |

No demand row. The row is `build` under the rule "two or more competitors rated yes".

## What changes

- An administrator adds a consumer to a catalogue and gets a key, shown once.
- A request carrying the key reads that catalogue's protected feeds as the consumer, and nothing else.
- A consumer can hold two keys at once, so a partner can switch without an outage. Each key shows when it was last used, and each can be revoked.
- The existing account-based access keeps working, and the screen shows the instance-wide list it uses.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `int-consumer-keys` | Give an outside consumer its own credentials for a protected feed. | partial | no per-consumer, per-catalogue credential; no screen |

## Existing work it builds on

- Main spec `ooapi-catalog-publication`, OOAPI-008 (consumer-credential access through OpenRegister's schema authorization) and OOAPI-010 (admin configuration). OOAPI-008's own scenario speaks of "a consumer credential issued for catalog 'hva-onderwijs' via admin-settings"; this change builds that issuing.
- The archived change `2026-07-13-ooapi-catalog-publication`, whose design left per-catalogue scoping as an open question (`SettingsService.php:505-516`).
- Precedent: integriq's merged change `access-consumer-credentials` (several credentials per consumer, generated on the server, shown once, stored as a hash, rotation by adding before revoking), read on ConductionNL/integriq development.

## Out of scope

- OAuth2 client credentials and SURFconext federation. OOAPI-008 keeps that as a follow-up.
- Public feeds. DCAT and the public publication API stay anonymous; this is for feeds a catalogue does not publish to everyone.
