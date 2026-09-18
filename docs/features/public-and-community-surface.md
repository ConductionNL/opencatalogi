# The public and community surface

The parts of the app a reader meets without an account: a status page, a notice
board, a feed, a vote, and an endpoint that renders this app's markup the way
this app renders it.

## The status page

`GET /apps/opencatalogi/api/status` lists named components with the state
somebody set on them.

It probes nothing. A status page that infers a component's health from a health
check tells the reader that the monitoring is up, which is the failure mode
every incident starts with. The response carries `probed: false` so a caller
cannot mistake a green for a measurement.

A component whose state has not been touched within the staleness period
(`status_staleness_hours`, 24 by default) is marked `stale: true` and shows the
date it was last set. A page that is out of date is worse than no page, and the
one thing it must not do is render a confident green over a fact nobody has
checked since Tuesday.

A status page this app could not read answers 503 with `status-unreadable`. It
never answers 200 with an empty list, because an empty status page reads as
"nothing is wrong".

### Subscribing

`POST /api/status/subscribe` records a request, unconfirmed, and the one-time
token goes to the address rather than back to the caller. Returning it would let
anyone confirm a subscription for an address that is not theirs.

An unconfirmed address is never a recipient. Subscribing somebody else to an
alert stream is a way to send mail on their behalf.

This app builds no second mailing mechanism. The `serviceStatus` schema declares
its state change under `x-openregister-notifications`, and `SubscriptionService`
decides who is eligible; the sending is the engine's.

## The banner

An instance banner has a body, a period, a severity and a dismissable flag. It
shows to every signed-in user between its dates and to nobody outside them.

A banner belongs to the instance, not to a catalogue, because planned
maintenance is not per catalogue. A dismissable banner a user dismissed is not
shown to that user again, and is still shown to everyone else: a dismissal is
one person's. A banner marked not dismissable keeps showing, because a critical
maintenance notice is not something a click makes go away.

A banner whose period cannot be read is refused at the save and is not shown, so
no banner can carry on for ever.

## Notice boards

A publication is a document the Woo obliges us to hold. A notice is something a
municipality wants to say this week. Different lifetimes, different obligations,
different readers.

A notice is therefore its own schema and **never** enters the sitemap or the
DiWoo feed. Reusing `publication` for a storingsmelding would put it in both.

Comments on a notice are a moderation duty, so they are off by default, and a
board that enables them names a moderator or the save is refused with the
reason.

## The feed

`GET /api/feeds/{catalogSlug}` is a catalogue's published and updated records
plus its current notices, as Atom, readable without an account. A ketenpartner
who wants to watch without a webhook reads this.

The access check is the publication's, run per entry, not a rule of the feed's
own. A draft is absent because it is not published. Two access decisions
disagree eventually, so there is only one.

## The vote

`POST /api/records/{id}/vote` records one vote per reader per record.
Participatie and inspraak are the use, and both are acts where the count is the
point.

The distribution is readable and an individual vote is not: a vote on an
inspraak item is an opinion attached to a person. The reader is held as a salted
hash of their token, and the hashes never leave this app, because a reader hash
is stable across records and publishing them would let anyone correlate one
person's votes across every item they voted on.

A record that accepts no votes, including a draft, answers the same 404 as a
record that does not exist. A different answer would let a reader confirm that a
draft exists.

## The markup endpoint

`POST /api/markup/render` takes this app's markup and returns the HTML this app
renders for it. No side effect, nothing stored. A mobile client that renders its
own markdown shows something different from the website, and the difference is a
defect report nobody can reproduce.

### The dialect

| Construct | Written as |
|---|---|
| Heading | `# Kop` through `###### Kop` |
| Paragraph | A block of text |
| Bold | `**vet**` |
| Italic | `*schuin*` |
| Inline code | `` `code` `` |
| Code block | Three backticks, the code, three backticks |
| Unordered list | Lines starting `- ` or `* ` |
| Ordered list | Lines starting `1. ` |
| Blockquote | Lines starting `> ` |
| Link | `[tekst](https://example.org)` |

Everything else is text. Every character of input is escaped before any markup
rule is applied, so a `<script>` in the input cannot become a tag in the output.
A link whose scheme is not `http`, `https` or `mailto` renders as text and never
as a link: a renderer that emits whatever scheme it was handed turns every
client of this endpoint into a way to run somebody else's code.

Bodies over 64000 characters are truncated, and the response says so.

### What is not claimed

`ViewObject.vue` and `WooRedactionView.vue` still render markdown in the browser
with `marked`, so byte-parity between those two views and this endpoint is not
claimed. Migrating them to call this endpoint is follow-up work; until then the
endpoint is the contract and those two views are the exception.
