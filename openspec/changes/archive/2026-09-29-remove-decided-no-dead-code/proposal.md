---
kind: code
---

# Proposal: remove-decided-no-dead-code

## Why

Seven capability rows were decided no on 2026-09-28 (`gap-decisions.json`), and Ruben chose on 29 Sep (decision 9) to remove the code behind them in one cleanup rather than keep it. Each of these pieces had an endpoint or a service, but nothing in the app ever called it, or the call could never succeed on the shipped schemas. Dead endpoints still count as attack surface, still need tests, and still read as features in the API document.

## What goes

| row | what is removed | why nothing calls it |
|---|---|---|
| `woo-process` | `POST /api/publication-process`, `POST /api/publication-process/step`, `PublicationRulesController::startProcess/completeStep/actor`, `PublicationProcessService` | no `src/` caller of `/api/publication-process`; the process was returned, never stored |
| `woo-zienswijze` | `POST /api/publication-process/zienswijze`, `PublicationRulesController::raiseZienswijze`, `ZienswijzeService` | no `src/` caller; the ask was returned, never stored or sent |
| `dec-stamp` | `GET /api/publications/verification-key`, `POST /api/publications/verify` and their OPTIONS routes, `PublicationDisclosureController::verificationKey/verifyDocument/preflightedCors`, `DocumentStampService` and its registration | `stamp()` had no caller and nothing set `publication_signing_key`, so verify could only answer "no key" |
| `pub-status-subscribe` | `POST /api/status/subscribe`, `GET /api/status/recipients`, their OPTIONS route, `CommunityController::subscribe/subscriptionRecipients`, `SubscriptionService`, `StatusPageService::stateChanged` | no confirm route existed, so no subscription could ever be confirmed and the recipient list was always empty |
| `pub-vote` | `POST /api/records/{id}/vote` and its OPTIONS route, `CommunityController::vote`, `VoteService`, the `IdentifiesTheReader` trait | `votingEnabled` is declared in no schema, so every vote was refused |
| `svc-articles` | `POST /api/knowledge-articles/{id}/verdict` and its OPTIONS route, `POST /api/knowledge-articles/extract`, `ServiceCatalogueController::recordVerdict/extractArticle`, `KnowledgeArticleService` | no route let a reader find or read an article, and no `src/` caller used either endpoint |
| `wr-redact` | `src/views/woo/WooRedactionView.vue` and its mount in `WooBatchDetail.vue` | never shown (`activeDocument` was never set), and it POSTed to a PUT route; redaction belongs to filinq |

The requirements behind them are taken out of the open changes that declared them (REQ-PIN-104, 105, 109; REQ-PSC-104, 105; REQ-PCS-102, 106), and the main `woo-transparency` spec loses "Redaction with WOO context".

## What stays

The register schemas that were declared for these features (`publicationProcess`, `zienswijzeAsk`, `knowledgeArticle`, `articleVerdict` and friends) stay: removing a schema is a data migration, and no code writes to them now. The rows stay decided no and can be reversed; the code is in git history.
