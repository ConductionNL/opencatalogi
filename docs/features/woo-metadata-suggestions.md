# Woo metadata suggestions

A publication needs its Woo information category, its publisher organisation and its
document handling before the national Woo index takes it. When one of them is missing,
OpenCatalogi proposes a value. Nothing is written to the publication until an editor
accepts the proposal.

## Where suggestions come from

- **The rules.** When a publication is created, OpenCatalogi looks at what it already
  knows: the schema the publication is in, the organisation of the catalogue that lists
  that schema, and how the record arrived. It proposes a category when exactly one
  category covers the schema, the catalogue's organisation when it has a valid TOOI
  identifier, and a document handling for a record a synchronisation brought in.
- **Hermiq, on request.** With Hermiq installed and its form fill feature enabled, an
  editor can press **Ask Hermiq**. Hermiq reads the text OpenRegister extracted from the
  publication's documents and picks a category and a document handling from the allowed
  values. Hermiq only adds suggestions; the ones the rules made stay as they are.

Every value is checked against the TOOI and DiWoo value lists first. A value that is not
on the list is never proposed. A field that already has a value gets no proposal.

## Deciding

The **Metadata suggestions** card on the publication page lists the suggestions that are
waiting. Each one shows the value, who proposed it and why. An editor who may change the
publication presses **Accept** or **Reject** on each suggestion; there is no accept-all.

- **Accept** writes the value to the publication and records who accepted it and when.
- **Reject** leaves the publication as it is and records who rejected it and when.
- A reader who may not change the publication sees the suggestions but no buttons, and the
  server refuses a decision from them.

## Filled automatically

When an officer creates a publication and a field has only one value they could choose,
the field fills itself. This happens for the organisation when the officer is a member of
one organisation only, and for the category when only one category covers the
publication's schema. The card says "Filled automatically: the only value you can choose"
for each such field, and the publication records the field, the value, the rule and the
time in `filledByRule`. With two or more possible values, or when the possible values
cannot be worked out, nothing is filled. A Hermiq suggestion is never filled in this way.

## API

| Call | What it does |
|---|---|
| `GET /api/publications/{id}/metadata-suggestions` | The suggestions, whether Hermiq can be asked (`hermiq`) and whether the caller may decide (`canDecide`) |
| `POST /api/publications/{id}/metadata-suggestions` with `source=hermiq` | Ask Hermiq; answers 409 `hermiq-unavailable` without Hermiq |
| `POST /api/metadata-suggestions/{id}/accept` | Accept; 403 without the right to change the publication, 409 when already decided |
| `POST /api/metadata-suggestions/{id}/reject` | Reject; the same refusals |

## Setting it up

The suggestion schema is imported with the publication register. Without OpenRegister, or
before the register is imported, the calls answer 503. Hermiq is optional: without it the
**Ask Hermiq** button does not show.
