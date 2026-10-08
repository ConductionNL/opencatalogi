---
kind: code
depends_on: [harvest-feed-intake, openregister/app-harvest-fetchers-and-flow-node]
---

# Proposal: woo-obligation-overview

## Why

Nobody can see in one place what must still be published. `ObligationOverviewService::assemble()` (`lib/Service/Publication/ObligationOverviewService.php`) merges obligations from registered sources and marks each one published, late, due or unknown, but nothing calls it and nothing gives it any data. The `obligationSource` schema exists (`lib/Settings/register.d/publication-inspection-and-the-national-indexes.json`, properties `appId`, `title`, `kind`, `enabled`, `lastReadAt`) and the setting key `obligation_source_schema` points at it, but no reader fills it. `PublicationRulesController` says as much in its docblock (REQ-PIN-110 unmet).

Row, opencatalogi matrix: `woo-obligations`, "See in one overview what must still be published, fed from every source system." Own rating no, state building.

Decision. The row is in the core Woo area (area `woo`), so it is `build`, although no competitor cell is rated yes. Recorded so it can be reversed.

## What is already built, and what is not

Built: the assembler with its states and its unread-source list, the `obligationSource` schema, the setting key.

Not built: a reader per source app, the endpoint, the page, and the link from a late row to the record. Source systems that are not Nextcloud apps arrive through harvesting, which runs on OpenRegister since decision 80 (`openregister/app-harvest-fetchers-and-flow-node`): a harvest feed is an OpenRegister `Source` with `application: opencatalogi` (`harvest-feed-intake`), each harvested item is a `SyncRecord` with the draft publication it produced. This change reads those for that source.

## What changes

- A read contract for source apps: an app that registers as a source answers a request for its obligations (title, due date, record reference, published flag). A source that cannot answer stays visible as unread with the reason.
- `GET /api/obligations` (admin) calls the readers, hands the answers to `assemble()` and returns the result.
- An Obligations page in the admin navigation lists the rows with state, source and a link to the record, and shows the unread sources above the table.
- Harvested records count as one more source, `harvest`: every OpenRegister sync record of an OpenCatalogi harvest source whose publication is not public yet is an obligation, due a number of days after the record was first imported that the feed sets (`config.publishWithinDays`); without that setting its due date is unknown.

## Rows this closes

| matrix | row id | what is missing |
|---|---|---|
| opencatalogi | `woo-obligations` | a caller, a reader per source and a page |

## Out of scope

Creating obligations from rules (`REQ-PIN-100` family), reminders and notifications for late rows.
