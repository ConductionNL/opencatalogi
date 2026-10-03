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

An administrator announces a decision from the publication page, in the
Official notice block. They choose the publication type and the effective date,
and the block lists each channel with its result.

### Channel sources

Each national channel is sent through an integriq source. You choose it in the
admin settings, Woo section, under National delivery channels: enter the slug
of the source as integriq shows it, for the Woo index, the national publication
platform and PLOOI. A channel without a source fails at once, with a message
that names the channel and the `channel_sources` setting.

The official notice for the national publication platform goes through
integriq's `publicatie` gateway as a reference to the document plus a
publication instruction, never as the document itself.

### PLOOI

Turn on Deliver to PLOOI on a catalogue, and every publication in it is sent
to PLOOI, the delivery API of open.overheid.nl, when it becomes public. The
delivery runs in a background job, so publishing never waits for PLOOI. The
publication page shows the result in the PLOOI delivery block: the status, the
time, the identifier PLOOI gave, and the reason when it failed. A failed
delivery does not undo the publishing.

### Woo information categories

You file a publication under one of the 17 information categories of the Woo
(Wet open overheid). Pick it in the Woo information category field of the
Publication block on the publication page. The field stores a code from
`infocat001` to `infocat017`. A publication with any other code appears in no
category sitemap.

Each category has its own sitemap, which the national Woo index reads. That
sitemap lists the catalogue's publications filed under the category. An
instance that still runs a register titled `woo` keeps its old lookup too: the
schema named after the category adds its publications to the same sitemap.

`GET /api/woo/categories` returns the 17 codes with their Dutch and English
names. A Woo request batch you publish is filed under `infocat014`, Woo requests
and decisions.

## Which collections publish

`GET` and `POST /api/published-collections` hold the configured set. A
collection added on a running instance publishes its records from that moment,
without a release. A configuration that cannot be read refuses: read as
"publish nothing" it silently stops a statutory publication, and read as
"publish everything" it publishes what nobody approved.

## Which parts of the published standards this implements

- **Woo actieve openbaarmaking** as a rule engine and an obligation overview. The DIWOO and TPOD payload profiles themselves belong to
  the sitemap and DCAT surfaces, not here.
- **Terinzagelegging** as a window with a per-case document set and a link
  checked at the read. The statutory terms themselves are declared per record
  type by the organisation; this app computes from them and asserts none.
- **The national publication platform and the national Woo index** are reached
  through integriq's gateway. This app composes the notice and the
  registration, records the answer, and holds no transport.

## Publish, withdraw and publish again

The publication page has a Publication status section. It says whether the publication is a draft, scheduled, public, withdrawn or archived. It shows only the buttons that apply.

- **Publish now** makes a draft or scheduled publication public at once.
- **Withdraw** asks for a reason and takes the publication off the public site at once. Every national channel it reached gets a withdrawal: the Woo-index always, PLOOI when it was delivered there. The message names any channel that has not confirmed.
- **Publish again** makes a withdrawn publication public. Earlier depublications stay in its history.

To take down one document, choose it in the Withdraw dialog. That document leaves the public site and the sitemap. The rest of the publication stays public.

Archive stays the final retention step. It is not a way to withdraw.

You need the right to change the publication. Without it the server refuses, and nothing is sent or changed.

| call | what it does |
|---|---|
| `GET /api/publications/{id}/visibility` | answers `{state}` |
| `POST /api/publications/{id}/publish` | publish now or again; 409 when it is already public |
| `POST /api/publications/{id}/withdraw` | body `{reason}`; 409 when it is not public or scheduled |
| `POST /api/publications/{id}/files/{fileId}/withdraw` | body `{reason}`; withdraws one document |
