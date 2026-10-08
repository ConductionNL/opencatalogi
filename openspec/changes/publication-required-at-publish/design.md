# Design: publication-required-at-publish

## D1. The declaration

In `lib/Settings/publication_register.json`, schema `publication`, inside the `x-openregister-lifecycle` that `publication-lifecycle-on-or` writes:

```json
"states": {
  "draft":     {"fields": {"required": []}},
  "in_review": {"fields": {"required": [{"fields": ["summary", "description", "organization"]}]}},
  "approved":  {"fields": {"required": [{"fields": ["summary", "description", "organization"]}]}},
  "published": {"fields": {"required": [{"fields": ["summary", "description", "organization"]}]}}
}
```

No `groups` key: the rule holds for everyone. The schema's top-level `required` stays `["title"]`. Schema version bumped. If `publication-lifecycle-on-or` has not merged, this change waits: it needs the stored `draft` state, otherwise every publication sits in `published` and the rule would refuse saving an incomplete one at all.

## D2. Existing publications

A published publication that already lacks a summary keeps its state; OpenRegister evaluates the rule on save, so its next edit is refused until the field is filled. The repair step of `publication-lifecycle-on-or` classifies legacy publications; this change adds a count to its log of published publications that miss a field now required, so an administrator knows how many will need a fix on their next edit. Nothing is moved back to draft.

## D3. Screens

- `OcNieuwePublicatie` and `OcPublicatieBewerken` label Title "(required)" today. Summary, Description and Organisation get the hint "Required to publish". The board has no such hint; it is added under each field's label in the board's hint style ("One or two sentences. Shown in search results.").
- `OcPubliceren` (publish dialog for one or many): before Publish, the dialog lists each selected publication that misses a field with the field names ("Council papers for 15 October: summary, organisation"), and the confirm button publishes only the others and says how many ("Publish 1 of 2 publications"). The list is computed client side from the same three field names, which the page reads from the schema's lifecycle (not a hard-coded list), and the server's refusal remains the authority: a 422 from OpenRegister is shown on the publication it names.

## D4. Tests

Unit: a schema test loads `publication_register.json` and asserts the declaration; an OpenRegister-backed test (skipped with a named reason without OpenRegister next to the app) saves a draft without summary (accepted) and transitions it to `published` (refused naming `summary`). e2e on the publish dialog.
