# Tasks: publication-inspection-and-the-national-indexes

## 1. Publication as a rule on a record type

- [x] 1.1 `publicationRule`: record type, visible parts, anonymous permission set, conditions (REQ-PIN-101)
- [x] 1.2 Enforce the anonymous permission set at the read, in the API and in the page alike (REQ-PIN-101)
- [x] 1.3 Preview a rule against a sample of existing records before it is saved (D1, risks)
- [x] 1.4 `publicationText` declared per type, rendered with the publication (REQ-PIN-102)

## 2. The decision type's rules

- [x] 2.1 Validate `publicationDate` and the statutory response date computed from it against the decision type (REQ-PIN-102)
- [x] 2.2 Refuse publication when the decision type's rules are unmet, with the reason returned to the case app (REQ-PIN-102)

## 3. Terinzagelegging

- [x] 3.1 `inspection`: record, chosen documents, start, end from the statutory term, link (REQ-PIN-103)
- [x] 3.2 Choose the documents that form the inspection set when the publication is made (REQ-PIN-103)
- [x] 3.3 Refuse the link on read once the window has closed, without waiting for a job (REQ-PIN-103)
- [x] 3.4 Available to any record type that declares an inspection term, not to one type (REQ-PIN-103)

## 4. The process around a publication

- [x] 4.1 The four steps: documents, zienswijze round, approval, channels, each recorded (REQ-PIN-104)
- [x] 4.2 Configure the steps away for a municipality that wants one click (REQ-PIN-104)
- [x] 4.3 The zienswijze ask over an identifying channel, with the answer recorded against the publication (REQ-PIN-105)
- [x] 4.4 Hold the publication while an ask is open inside its term (REQ-PIN-105)

## 5. Taking it back

- [x] 5.1 Depublish in one action, recording who and why (REQ-PIN-106)
- [x] 5.2 Withdraw from every channel the publication reached, and record each acknowledgement (REQ-PIN-106)
- [x] 5.3 Show an unacknowledged withdrawal as outstanding (REQ-PIN-106)

## 6. The national channels

- [x] 6.1 Compose the official notice for the national publication platform and the local channel, and hand it to integriq's gateway (REQ-PIN-107)
- [x] 6.2 Register published records with the national Woo index through the same gateway, recording the index's answer (REQ-PIN-107)
- [x] 6.3 Configure which collections are published and on what conditions (REQ-PIN-108)

## 7. The stamp, the overview and the search

- [x] 7.1 Sign the published document and its publication metadata; publish the verification key (REQ-PIN-109)
- [x] 7.2 A reader verifies a published document against the published key (REQ-PIN-109)
- [x] 7.3 The obligation overview over every registered source, showing what must be published, what is, and what is late (REQ-PIN-110)
- [x] 7.4 Public plain-word search over published information, naming the dossier each document belongs to (REQ-PIN-111)

## 8. Quality

- [x] 8.1 PHPUnit: the permission set at the API read, the expired inspection link, the decision-type validation, the withdrawal acknowledgement, the signature
- [x] 8.2 Playwright `tests/e2e/publication-inspection-and-the-national-indexes.spec.ts`: publish a record type anonymously, open an inspection inside its window and again after it, then depublish
- [x] 8.3 CORS headers on every new public endpoint, per the app's public endpoint rule
- [x] 8.4 Reuse OpenRegister's date and identifier utilities under ADR-011; add none
- [x] 8.5 Dutch and English strings; docs; `openspec validate publication-inspection-and-the-national-indexes --strict`

## What this change does not do, and why it says so

- **The stamp is symmetric.** The document and its publication metadata are
  covered by an HMAC under the organisation's key, and the published key is a
  fingerprint, so a reader checks a document through this app's verify endpoint.
  A detached signature a third party could check offline, and anything from the
  eIDAS qualified-seal family, are not implemented. `eidas-koppeling-publicatie`
  is where that belongs.
- **The transport stays integriq's.** The notice for the national publication
  platform and the registration with the national Woo index are composed here
  and handed to the gateway. No national endpoint is called from this app, and
  with no gateway installed the delivery is reported as unreachable rather than
  as made. The DIWOO and TPOD payload profiles themselves are the sitemap and
  DCAT work, not this change.
- **The obligation overview reads what it is given.** `ObligationOverviewService`
  assembles from every registered source and names the ones it could not read.
  The per-source readers, and the harvest intake it will also read from, are
  `harvest-feed-intake`.
- **The anonymous permission set is enforced in this app's public handlers.**
  Enforcing the same set inside OpenRegister's own object API, so a leaf app
  cannot serve a withheld property by going around this app, is an OpenRegister
  change and is not attempted here.
