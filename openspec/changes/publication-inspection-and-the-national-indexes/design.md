# Design: publication-inspection-and-the-national-indexes

## D1. Publication is a decision about a type, not a pile of objects

`PublicationController` publishes chosen objects today. That is a person
picking rows. Woo actieve openbaarmaking is a standing rule: for this
record type, these parts are public, to a reader with no account.

So the unit is a **publication rule** on a record type: the type, the
visible parts, the anonymous permission set, and the conditions under
which a record of that type becomes public. Records follow the rule.
Nobody picks.

A picked list goes stale the moment a new record arrives, and going stale
here means failing to publish something the law says must be published.

## D2. The visible parts are a permission set, and it is the only one

The anonymous permission set names the properties a reader with no
account may read. It is enforced where the read happens, not by a
renderer that leaves a field out. A field absent from the page and
present in the API is the shape of every accidental disclosure.

## D3. Terinzagelegging is a window, and the window owns the link

An inspection is a first-class object: the record, the set of documents
chosen for it, a start, an end computed from the statutory term, and a
link. The link stops working when the window closes. It stops working
because the window closed, not because a job ran; the check is on the
read.

The inspection set is chosen when the publication is made. rx-mission
does the same, and the reason is legal: which documents form part of the
decision for inspection is a judgement made per case, not a rule per
type.

## D4. Publication and inspection are two acts on one record

A besluit can be bekendgemaakt without going ter inzage, and documents
can go ter inzage before a besluit exists. They share the record and
nothing else. Two objects, two lifecycles, one record.

## D5. The decision type carries the rules, and they are validated

`publicationDate` and the statutory `responseDate` computed from it are
validated against the decision type when the decision is published.
Publicatieplicht is a property of the besluittype, and the validation is
what stops an unpublished besluit closing a case. The validation runs
here because the rule is about publication; the refusal travels back to
the case app as a refusal to publish.

## D6. A walked process, with the steps a Woo publication actually has

`publish` as a button hides four decisions: which documents, whether a
zienswijze round is needed, who approves, and which channels receive it.
So publication is a small process with those four steps, each recorded.
A municipality that wants one click configures the steps away; a
municipality that wants the audit trail has it by default.

## D7. The zienswijze round is a consultation, over a channel that identifies

Before information about an interested party is published, that party is
asked. The ask goes out over a channel that identifies the recipient, the
answer is recorded against the publication, and the publication cannot
advance past the round while an ask is open and unanswered inside its
term.

The portal identity is portaliq's; this change raises the ask and reads
the answer. It does not build a second login.

## D8. Depublication is one action and it is honest

One action takes a publication down, records who did it and why, and
removes it from every channel it reached, including the national ones.
The record of the publication stays. Publishing a name by mistake needs
an undo, and an undo that leaves the document in a harvester's copy is
not one, so the channels are told and the telling is recorded.

## D9. The overview is fed, not owned

The publication obligation is on the organisation, not on one case
system. So the overview reads publications from every application that
registers as a source, and from other case systems over the same intake
the harvester already uses. Publishing only what one product holds is
publishing part of it, which is the lane's own sentence.

`dcat-ap-harvest` and the harvest changes already bring records in. This
adds the obligation view over them: what must be published, what is, and
what is late.

## D10. The stamp says who published, and it is verifiable

A published document carries a digital stamp: a signature over the
document and its publication metadata, verifiable by a reader against the
organisation's published key. A published document that cannot be shown
to be the authentic one is the disinformation problem the vendor names.

The stamp is not a watermark and it is not a claim in the metadata. It is
checkable, or it is decoration.

## D11. Public search shows the dossier, because a document alone misleads

A document out of its dossier is the commonest way a published answer is
read wrongly. The public search result names the dossier a document
belongs to and links to it, and the search runs over what the anonymous
permission set allows and nothing else.

## Risks

- **A publication rule that is too wide.** A rule is validated against
  the record type before it is saved, it is previewed on a sample of
  records, and the preview is part of the tasks rather than of a manual.
- **The inspection link outliving its window.** The check is on the read.
  The tests assert an expired link refuses after the clock passes, with
  no job run.
- **A documented-only requirement built to the wrong shape.** Six of the
  nine passers here are vendor claims. Each requirement records which
  kind of evidence it rests on, so a later reading knows what was assumed.
- **Depublication that the national index does not honour.** The
  withdrawal is sent and its acknowledgement recorded; an unacknowledged
  withdrawal is shown as outstanding rather than as done.
