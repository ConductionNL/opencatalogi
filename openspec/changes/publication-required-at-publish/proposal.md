---
kind: code
depends_on: [publication-lifecycle-on-or]
---

# Proposal: publication-required-at-publish

## Summary

An editor saves a publication as a draft with only a title. The fields a public record needs (summary, description, organisation) become required the moment it moves into review or is published, and the refusal names every missing field. OpenRegister enforces it; OpenCatalogi declares the rule on its schema and shows it on its forms.

## Why

Row `pub-draft-required`, "Save a publication as a draft with required fields still empty, and have them enforced only when it is published." State `specified`, ours `no`, owner openregister. Re-rated 7 October 2026: "OpenRegister shipped field rules by state; opencatalogi has neither the draft state nor the rule that makes fields required in published. No opencatalogi change declares that rule yet."

The delivered change is OpenRegister's `field-rules-by-state` (archived 2026-10-05, 13 of 13 tasks): `x-openregister-lifecycle.states.<state>.fields` may declare `required` lists, evaluated on save by `lib/Listener/StateFieldRuleListener.php`, and a state may declare entry conditions whose refusal names the failing clause. The draft state comes with the open change `publication-lifecycle-on-or` (issue opencatalogi#1754): stored states `draft`, `in_review`, `approved`, `published`, `archived`, initial `draft`. Its tasks declare no required-at-publish fields. This change adds them.

## What changes

- The publication schema's lifecycle declares `summary`, `description` and `organization` required in `in_review`, `approved` and `published`. `draft` requires only `title`, as the schema does today.
- Every path that publishes (Publish now, Publish later, Publish retroactive, the mass publish dialog, `publishWithoutReview`, a Woo batch publish) goes through OpenRegister's transition, so the rule holds on all of them without an OpenCatalogi check of its own.
- The publication forms mark these fields "Required to publish" and the publish dialogs list the publications that cannot be published yet and why, before the editor confirms.

## Rows

| row | name | ours | what this closes |
|---|---|---|---|
| `pub-draft-required` | Save a publication as a draft with required fields still empty, and have them enforced only when it is published. | no | the rule on the publication schema and its place on the forms |

## Out of scope

- The draft state itself and the transitions: `publication-lifecycle-on-or`.
- A Woo category required for publications in a Woo catalogue. The rule depends on the catalogue, not the publication, and field rules by state are per schema. The Woo readiness check keeps reporting a missing category.
