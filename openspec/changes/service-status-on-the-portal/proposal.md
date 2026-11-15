---
kind: code
depends_on: []
---

# Proposal: service-status-on-the-portal

## Why

Board **OcStatus** draws the site page "Status van de dienstverlening": each
service with its state (Werkt, Verstoring, Onderhoud gepland), what is going on
and since when. opencatalogi keeps the states (`serviceStatus`, set through
`POST /api/status`) and answers them at `GET /api/status`, and the portal has
no page that shows them: a site page reads an app's data only through the
app's portal contribution, and opencatalogi offered none.

## What changes

- `PortalContributionProvider` implements portaliq's optional
  `getPublicIndex(string $portal)` and `getPublicIndexKinds(string $portal)`:
  one kind, `serviceStatus` ("Status van de dienstverlening") with the columns
  Dienst, Toelichting and Status, and an item per component in its order.
- `ServiceStatusIndex` reads the components through the same
  `StatusPageService::render()` as the public endpoint. A state that is not
  "available" says since when it holds; a register that cannot be read gives
  no items, never a list that says everything works.
- The example set adds the five services of the board.

The site page itself is portaliq's (`/status`, a public table over this kind).
