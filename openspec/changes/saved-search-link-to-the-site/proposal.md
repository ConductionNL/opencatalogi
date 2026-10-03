# A saved-search notice links to the publication page of the site

## Why

Woo round 3 (hydra woo-citizen-journey J6, Ruben 2026-10-02): the saved-search match notice linked to the raw
publication API (`http://localhost/index.php/apps/opencatalogi/api/publication/<id>`). A resident who opened it got
JSON, on the wrong host. The same link is used in the resident's dossier and on a shared dossier. The saved-search
list showed the stored frequency (`immediate`, `daily`) instead of words, and both portal pages printed their name
twice (the page heading and a `##` line in the intro). The pages declared no `group` for the site's menu.

## What changes

- `PublicationLinker::url()` links to the publication page of portaliq's site
  (`/index.php/apps/portaliq/site?route=/publicatie/<id>`) whenever portaliq is installed, absolute through
  `IURLGenerator::getAbsoluteURL()`. An admin's `publication_url_template` still wins. Without portaliq the link
  stays the public API, the only public address then.
- The frequency reads "Direct", "Dagelijks", "Wekelijks" in the list and card (`columns[].valueLabels`) and in the
  form.
- Both pages declare `group: "Openbare informatie"`; their intro text drops its own heading.

## Host and port in a background job

The match runs in cron, where `getAbsoluteURL()` reads Nextcloud's `overwrite.cli.url`. That setting must hold the
public host and port (on the dev instance: `http://localhost:8080`, it read `http://localhost`). Code cannot know the
public address in cron any other way.

## Impact

- `lib/Service/Portal/PublicationLinker.php`, `lib/Portal/PortalContributionProvider.php`, tests. No register change.
