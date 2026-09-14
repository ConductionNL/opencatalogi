# Design: published-service-and-case-type-catalogue

## D1. One catalogue object, two directions

A `serviceCatalogueEntry` is a published record in a catalogue, like every
other published record here. What makes it an entry is three properties:
what the resident gets, what it costs them in money and time, and how to
start it.

The inward direction, case types imported from the national catalogue, is
not a second catalogue. It is a harvest source and an import, and the
harvest changes already carry that shape.

## D2. An entry starts a form, and the entry does not contain it

The entry carries a form binding: the case type, the audience and the
form name. Portaliq resolves it and renders. The catalogue never holds a
field list, and portaliq never holds a catalogue.

The split is the ownership rule applied twice. Publication lives where
publication lives. The portal is a surface.

## D3. Cost and duration are part of the entry, because they are the question

"Wat kost het en hoe lang duurt het" is what a resident asks before
anything else. GLPI's service catalogue carries it, and a catalogue that
lists services without it sends every reader to the telephone.

## D4. The case type publishes its form and its API description

Two links on a published case type: the form that starts it, and the
OpenAPI description of the interface behind it. An administrator uses the
first, an integrator the second, and both are published beside the
definition rather than hunted for.

## D5. Import is a sync with a date and a diff

An import from the national zaaktypecatalogus records the source, the
version and the moment. A resynchronisation shows what changed at the
source since, as a diff, before anything is applied. Nothing is applied
silently.

Applying silently would change a running configuration from outside the
organisation, which is the one thing an inrichtingscheck exists to
prevent.

## D6. The article is a publication, and the verdict is a count

A knowledge article is a published record in a catalogue, so it is
searchable and public by the rules that already govern publications. The
reader's verdict is a count on it: helpful, not helpful, nothing more.
The lane called this the honest version of the satisfaction row, and the
honesty is in the narrowness.

## D7. Extracting an article copies, it never moves

Turning a case answer into an article copies the text into a draft
article and links back to the case. The case keeps its answer. A draft,
not a publication, because an answer written to one applicant is rarely
the wording you want in public.

## Risks

- **A catalogue entry whose form no longer exists.** The entry is shown
  with its form unavailable, and the administrator sees which entries are
  in that state. It is not hidden, because a hidden obligation is worse
  than a broken one.
- **An import that overwrites local changes.** The diff is shown before
  anything applies, and a property changed locally is flagged in it.
- **An extracted article that carries personal data.** The extraction
  produces a draft and the draft is not public. The tasks include the
  reminder in the surface itself.
