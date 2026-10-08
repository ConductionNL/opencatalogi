---
kind: code
depends_on: [integration-feed-consumer-credentials]
---

# Proposal: publications-for-signed-in-readers

## Why

Some documents a municipality shares are not for everyone. Examples are the papers a resident sees about their own neighbourhood before a consultation, or a supplier's documents during a tender. Today a publication is public or it is not. To show one to residents who log in with DigiD, an organisation must put the whole portal behind a login.

opencatalogi matrix, row `pub-restricted`, "Show some documents only to readers who log in with DigiD or eHerkenning." Own rating partial, built.state `building`, owner `ConductionNL/portaliq`.

- Own note: "The gate is per portal, not per document. DigiD and eHerkenning login works through portaliq's generic OIDC broker ... opencatalogi has no notion of a publication visible only to authenticated citizens."
- Own evidence: portaliq `ContentController.php:105-125` gates page content by `portal.authentication.modes` with a minimum trust level, and `OidcClaimMapperService.php:74-91` ships digid, eherkenning and eidas presets. opencatalogi publications reach portaliq only through the `@PublicPage` federation endpoints.

The login half is built. The missing half is opencatalogi's: a publication that says who may read it, and a read path that serves it only to a portal that vouches for a logged-in reader.

## What changes

- A publication gets "Who can read it": Everyone, or Readers who log in with DigiD or eHerkenning, with a minimum level of Substantial or High.
- Every public surface leaves a sign-in publication out: the public API, search, the sitemaps for the Woo index, DCAT, OOAPI and federation to other directories. Anonymous readers get a 404, so they cannot tell such a publication exists.
- A portal holding a consumer key that may act for signed-in readers can read it, by sending the reader's assurance level. opencatalogi checks the level against the publication's minimum and logs every such read.
- A sign-in publication cannot carry a Woo information category. Woo publications are public by law; the save says so.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| opencatalogi | `pub-restricted` | Show some documents only to readers who log in with DigiD or eHerkenning. | partial | no per-publication audience, no trusted read path for a portal |

portaliq's sibling row `sib-opencatalogi-pub-restricted` was deferred on 27 Sep. Its half here is two cross-repo tasks.

## Existing work it builds on

- `integration-feed-consumer-credentials` (open change here): per-catalogue consumer keys. This change adds one permission to such a key.
- OpenRegister's conditional read rules on the publication schema (`authorization.read` with `group: public` and a `match`), which already decide what the public sees.
- portaliq's OIDC broker and claim mapper, which already know the reader's level.

## Out of scope

- Per-reader rights, such as "only the owner of this case". That is a portal case view, not a publication.
- Login inside opencatalogi. Readers never log in here; the portal does.
