# Active publication, inspection and the national indexes

Woo actieve openbaarmaking is a standing rule, not a person picking rows. For
this record type, these parts are public, to a reader with no account, under
these conditions. Records follow the rule and nobody picks, because a picked
list goes stale the moment a new record arrives, and going stale here means
failing to publish something the law says must be published.

## The publication rule

A rule names the record type, the properties an anonymous reader may read, and
the conditions under which a record of that type becomes public.

The anonymous permission set is enforced where the read happens. A property
outside it is absent from the response, not blanked and not nulled: a property
present with an empty value still tells the reader the record carries it. A
field absent from the page and present in the API is the shape of every
accidental disclosure, so the projection is made once, in
`PublicationRuleService`, and every public handler goes through it.

### Previewing before saving

`POST /api/publication-rules/preview` answers both halves: which records the
rule would publish, and which properties it would expose. The preview runs the
draft rule as it would be saved, so a rule that is switched off still shows
what it would do. A preview that answered "nothing" for a draft would be a
check that cannot see the thing it judges.

A rule with an operator this app does not know is refused. Read as "matches" it
publishes what nobody approved; read as "does not match" it silently withholds
what the law requires.

## The decision type

`POST /api/publication-rules/validate-decision` validates a decision against
its besluittype. Publicatieplicht is a property of the type, so the rules live
there and the validation happens here, where publication happens.

The statutory response date is computed from the type's term and the decision's
publication date. It is never taken from the decision: a typed response date is
a date somebody chose, and the statutory one is a date the law chose.

A missing publication date and an unreadable one are refused with different
reasons, because the two send the caller to different places.

## Terinzagelegging

`POST /api/inspections` opens a window on a record, for the term the record
type declares, over the documents chosen for it. Which documents form part of a
decision for inspection is a judgement made per case, not a rule per type, so
the set is chosen at publication.

`GET /api/inspections/{id}?token=…` is the link. It stops working because the
window closed, checked at the read, so a scheduler that has not fired yet
cannot leave documents readable past their statutory period. A closed window
answers 410 with its end date, so a reader who followed a link from a letter
learns the period is over rather than that something is broken.

A window whose dates cannot be read refuses. Read as open it publishes past the
term; read as closed it withholds what is owed.

## The process around a publication

Publish as a button hides four decisions: which documents, whether a zienswijze
round is needed, who approves, and which channels receive it. So publication is
a small process with those four steps, each recording who completed it and
when.

A municipality that wants one action configures the steps away. A skipped step
is recorded as configured off, never as done, so a later reading can tell an
approval that happened from one that was never asked for.

### The zienswijze round

An ask goes out over a channel that identifies the recipient. A channel that
cannot say who answered is refused, because the answer is what permits the
publication to go ahead. While an ask is open inside its term the publication
is held and the open ask is named. An ask past its term no longer holds it: the
party was asked and did not answer.

## Taking a publication back

`POST /api/publications/depublish` is one action. It records who and why, and
sends a withdrawal to every channel the publication reached.

A withdrawal a channel has not acknowledged is shown as outstanding. An undo
that leaves the document in a harvester's copy is not one, so a channel that
could not be reached is recorded as not reached and never as acknowledged.

## The national channels

`POST /api/publications/announce` composes the official notice for the national
publication platform and for the local channel and hands both to integriq's
gateway. No national endpoint is called from this app.

When a channel cannot be reached, the response is 502 and names it, and the
composed notices are returned anyway so an operator can see what would have
been sent. A 200 with nothing delivered would let a partial announcement read
as a complete one.

## Which collections publish

`GET` and `POST /api/published-collections` hold the configured set. A
collection added on a running instance publishes its records from that moment,
without a release. A configuration that cannot be read refuses: read as
"publish nothing" it silently stops a statutory publication, and read as
"publish everything" it publishes what nobody approved.

## The stamp

A published document carries a stamp over the document and its publication
metadata. A document whose bytes changed fails the check, and so does one whose
publication metadata was edited, because a correct document published under a
false date is its own kind of falsehood.

`GET /api/publications/verification-key` publishes a fingerprint of the key,
and `POST /api/publications/verify` checks a document against it. With no key
configured, stamping refuses rather than producing a stamp made with an empty
key, which would verify against an empty key and tell every reader a document
is authentic while nobody checked anything.

## Which parts of the published standards this implements

- **Woo actieve openbaarmaking** as a rule engine, a walked process and an
  obligation overview. The DIWOO and TPOD payload profiles themselves belong to
  the sitemap and DCAT surfaces, not here.
- **Terinzagelegging** as a window with a per-case document set and a link
  checked at the read. The statutory terms themselves are declared per record
  type by the organisation; this app computes from them and asserts none.
- **The stamp** is a symmetric seal under the organisation's own key, verified
  through this app. A detached signature a third party could check offline, and
  anything from the eIDAS qualified-seal family, are not implemented:
  `eidas-koppeling-publicatie` is where that belongs.
- **The national publication platform and the national Woo index** are reached
  through integriq's gateway. This app composes the notice and the
  registration, records the answer, and holds no transport.
