---
kind: code
depends_on: []
---

# Proposal: the-public-and-community-surface

Round 4 discovery sweep, cluster 17 "The public and community surface"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Owner opencatalogi, size
M, decision D5, and cluster 50 depends on it. Candidates:
`C-communication-2`, `C-communication-9`, `C-communication-21`,
`C-communication-23`, `C-communication-35`, `C-communication-53`,
`C-intake-34` (`candidates.json`, lane lines `communication.tsv:74`,
`:19`, `:44`, `:45`, `:23`, `:64` and `intake.tsv:7`).

## Summary

A municipality needs somewhere to say things to the public that is not a
case. What is running and what is down. What is planned. What was
decided. And it needs the public to be able to answer: a vote, a comment,
a feed to subscribe to. opencatalogi already publishes. This adds the
surface around the publications.

## Why

dossiq rates all seven `no`. That is the whole cluster, and it is the
sharpest reading in the set: nothing in dossiq holds a public notice, a
status page or a reader's response.

The lane's clauses name what it costs. "DigiD is uit de lucht", "de balie
is gesloten" and "het zwembad is dicht" are what a gemeente's loket cannot
say today. Planned maintenance and storingsmeldingen have to reach a whole
afdeling before the phone does. Participatie and inspraak are real
municipal acts, and a vote that lands on the case beats a vote that lands
in a survey tool.

The plan's mechanism line: "extend opencatalogi's publication surface;
dossiq publishes and does not host".

## The passers that prove it

Thirteen systems pass a member, ten driven and three documented. Proving
system: gitlab.

| candidate | relevance | driven | documented | evidence the lane cited |
|---|---|---|---|---|
| `C-communication-35` | should | gitlab, openproject, tuleap | jira-data-center, youtrack | Admin, Messages, banners and notifications, with placeholders and a dismissable flag (`D-gitlab-10`, `D-openproject-5`, `D-tuleap-11`) |
| `C-communication-21` | must | | jira-service-management | publish, customise and subscribe to status pages, private status pages, webhooks, audit logs (`D-jsm-9`) |
| `C-communication-9` | could | openproject, redmine | | `resources :news`, `/news/:id/comments` (`D-redmine-16`, `D-openproject-4`) |
| `C-communication-53` | could | kanboard, vikunja | | `FeedController&action=project`, Atom (`D-kanboard-4`) |
| `C-intake-34` | could | taiga | youtrack | `/(?P<id>\d+)/voters` on epics, issues, tasks and stories (`D-taiga-5`, `D-youtrack-24`) |
| `C-communication-23` | could | plane | | Public board, `IssueVote` at `issue.py:654-657` (`D-plane-20`) |
| `C-communication-2` | could | forgejo, gitea | | `/markdown`, `/markdown/raw`, `/markup` (`D-forgejo-24`) |

`C-communication-21` is the only `must`, and it has no driven passer. It
is admitted under Ruben's answer to D21, documented candidates are
admitted and labelled, and under D6, every `must` enters.

## What opencatalogi builds

- **A public status page** that says what is running and what is not,
  with subscriptions so a reader is told when it changes.
- **An instance banner with a display period**, shown to every user of
  the instance between two dates.
- **A notice board per catalogue**: a dated post with comments and a
  feed, for the things that are announcements rather than publications.
- **The activity of a catalogue as a subscribable feed**, for a reader
  who wants to watch without an account and without a webhook.
- **A vote on a published record**, by readers who are not staff, with
  the distribution readable.
- **A markup rendering endpoint**, so a client shows what we show instead
  of guessing at our dialect.

## How dossiq consumes it

dossiq publishes and does not host. It hands opencatalogi a publication
and reads back whether it is published, and it does not gain a status
page, a banner or a comment thread of its own. Where a vote lands on a
published record that came from a case, dossiq reads the count through
the publication, not through a second store.

## Existing specs it extends

`publications` (the public read surface and its CORS rules), `catalogs`
(the catalogue a notice or a feed belongs to), `notifications` (the
subscription), `auto-publishing` and `search` (a notice is findable).

## ADRs

- ADR-031: the notice, the banner and the vote are declared objects, not
  PHP models.
- ADR-054: every added endpoint here is public, so it is hardened.
- ADR-082: a public write, which a vote and a comment are, is throttled.

## About D5

The plan names decision D5 on this cluster. D5 weighs five parked
candidates for revival: the board, the Gantt, the calendar client, the
satisfaction survey and the budget ceiling. None of the seven candidates
in this cluster is one of those five, so nothing in this change turns on
the answer.

## Out of scope

- The content of a publication. `publications` owns that.
- A forum or a threaded discussion board. The sweep put a threaded board
  per case domain in the `not` bucket, and D17 keeps it recorded rather
  than built.
- Sending a message to a named person. That is notification, and
  openregister owns the dialect under ADR-031.
