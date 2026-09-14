# Tasks: the-public-and-community-surface

## 1. The status page

- [ ] 1.1 `serviceStatus` schema: component, state, message, updated at (REQ-PCS-101)
- [ ] 1.2 The public status page, rendering components with their history and the date each state was set (REQ-PCS-101)
- [ ] 1.3 Subscriptions to the status page through the notification dialect, with a confirmed address for an anonymous reader (REQ-PCS-102)

## 2. The banner

- [ ] 2.1 `instanceBanner`: body, period, severity, dismissable (REQ-PCS-103)
- [ ] 2.2 Render it to every user between its dates, and remember a dismissal per user (REQ-PCS-103)

## 3. The notice board and the feed

- [ ] 3.1 `notice` schema per catalogue, with an optional link to a publication (REQ-PCS-104)
- [ ] 3.2 Comments per notice board, off by default, with a named moderator when enabled (REQ-PCS-104)
- [ ] 3.3 An Atom feed per catalogue over published records and notices, access-checked per entry (REQ-PCS-105)

## 4. The reader's answer

- [ ] 4.1 `vote` on a published record: one per reader token, distribution readable, individual votes not (REQ-PCS-106)
- [ ] 4.2 Throttle the vote and comment endpoints per address under ADR-082 (REQ-PCS-106)

## 5. The markup endpoint

- [ ] 5.1 A public render endpoint that takes our markup and returns our HTML, with no side effect (REQ-PCS-107)

## 6. Quality

- [ ] 6.1 PHPUnit: the feed omits a draft, one vote per token, a subscription needs a confirmation, the banner period
- [ ] 6.2 Playwright `tests/e2e/public-and-community-surface.spec.ts`: read the status page, subscribe, see a banner, vote on a published record
- [ ] 6.3 CORS headers on every new public endpoint, per the app's public endpoint rule
- [ ] 6.4 Dutch and English strings; docs; `openspec validate the-public-and-community-surface --strict`
