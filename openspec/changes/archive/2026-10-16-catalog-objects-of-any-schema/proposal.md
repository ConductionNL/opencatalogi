# Proposal: catalog-objects-of-any-schema

## Summary

A catalog's publications page makes objects of every schema readable, not only publications. Fixes ConductionNL/opencatalogi#1932.

## Why

A catalog can hold schemas other than the publication schema. The Componenten catalog holds publiccode components from the GitHub harvest (WOO-585). On `/publications/<catalogSlug>` such an object could not be read:

- Its `description` showed nowhere. The publiccode schema keeps descriptions per language in one free-form object, and the library drops such a property from the table and from the form unless it names a `widget`.
- Only publication rows opened a detail page. Other rows opened the form, which dropped the description too.
- The page had no filter and columns sidebar, unlike the library's index pages.
- A catalog without the publication schema got no column config, so every scalar property became a column.

## What changes

- A generic detail page `CatalogObjectDetail` at `/publications/:catalogSlug/:register/:schema/:id`, built from library widgets: a read-only Data card, the Related card and the audit trail in the sidebar. Rows of any pair other than the publication pair open on it, by click, View, or a ctrl or middle click in a new tab. Edit stays in the header's form.
- The Publications page sets `sidebar: { enabled, showMetadata }`.
- A new `otherPairConfig` on the Publications page gives a catalog without the publication pair the columns Name, Summary and Updated.
- The publiccode schema renders `description` with the `json` widget and fills `@self.summary` and `@self.description` from the Dutch, then the English description, through `configuration.objectSummaryField` and `objectDescriptionField`.

## Upgrade note

OpenRegister fills `@self.summary` and `@self.description` when an object is saved, so components harvested before this change have an empty Summary column. Re-import the OpenCatalogi settings (Admin settings → OpenCatalogi), so the publiccode schema gets its new `configuration` and `widget`, then run the GitHub harvest again. This change ships no repair step.

## Dependency on nextcloud-vue

The scenario "A component's description can be read per language" in `specs/publications/spec.md` depends on ConductionNL/nextcloud-vue#1623 (the CnObjectDataWidget fix for nested objects). That fix first shipped in the beta release `2.56.0-beta.36` and reached the stable line in `2.85.0`, so this change takes `@conduction/nextcloud-vue` `^2.88.0` (task 5.1). With it, the Data card shows the Dutch and English short and long description as readable nested rows instead of one JSON blob per language.

## Not in this change

- Per-user-language summaries. `@self.summary` is filled at save time from a fixed fallback chain.
