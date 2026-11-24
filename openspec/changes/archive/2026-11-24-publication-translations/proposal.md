---
kind: code
depends_on: []
---

# Proposal: publication-translations

## Summary

An editor writes a publication's title, summary and description in Dutch and adds an English (or other) version on the same edit page. The public API answers in the language the visitor asks for and falls back to Dutch per field. The publication page shows per language what is translated. The storage, the negotiation and the completeness are OpenRegister's translation machinery; OpenCatalogi declares which fields are translatable, passes the language through its public API, and draws the screens on boards `OcPublicatieBewerken` and `OcPublicatie`.

## Why

Row `pub-languages`, "Offer the publications and their labels in more than one language." State `specified`, ours `partial`. Its note: "Labels are translated. Publications are stored and served in one language. Multilingual content is specified (register-i18n), not built." And: "The archived openspec/changes/archive/2026-03-21-register-i18n ticks 12 of 12 tasks, but at f8c2317120 none of it is in the code."

The open change `register-i18n` has a 6-line design and a tasks file with one non-checkbox task ("Implementation planning, todo"); its spec still covers pages, themes, menus and glossary, which moved to portaliq (ADR-086). Nobody can build from it, which is why the gap scan reads it as a change with nothing left to do. This change is the buildable part for OpenCatalogi's own content: publications and catalogues.

Since then OpenRegister built what the old change wanted to build here (openregister development, specs `i18n-api-language-negotiation` and `i18n-source-of-truth`): schema properties declare `translatable: true` and `sourceLanguage`; reads honour `?_lang=`, `?language=` and `Accept-Language` (query, then header, then register default, then `nl`); writes take `X-Translation-Target-Language`; responses carry `X-Source-Language`; `GET /api/translations/object/{uuid}` returns per property and language whether a translation exists and whether it is outdated. The main spec `opencatalogi-adopt-or-abstractions` already says OpenCatalogi "MUST honour API language negotiation for translatable content" and "editorial UI MUST allow translation authoring". Neither is done: on development (c3b5d5c21) `lib/Settings/publication_register.json` has no `translatable` property and no OpenCatalogi controller passes a language to OpenRegister.

## What changes

- `title`, `summary` and `description` of `publication` and `title`, `summary` and `description` of `catalog` declare `translatable: true` with `sourceLanguage: "nl"`.
- The public publication and catalogue endpoints pass the visitor's `_lang` or `Accept-Language` to OpenRegister and return `Content-Language` and `X-Source-Language`. The DCAT feed writes each translated value as its own literal with a language tag.
- The edit page gets a language switch at the top: Dutch (source) and the other languages the administrator enabled. Writing in English saves with `X-Translation-Target-Language: en`. An empty translated field falls back to Dutch, and the page says so.
- The publication page gets a Languages card: per language whether it is the source, complete, or which fields are missing or outdated, with Translate in the editor.
- An administrator picks the offered languages in the settings (default Dutch and English).

## Rows

| row | name | ours | what this closes |
|---|---|---|---|
| `pub-languages` | Offer the publications and their labels in more than one language. | partial | publication and catalogue content in more than one language, authored and served |

## Relation to register-i18n

`register-i18n` stays open for its other content types; its pages, menus, themes and glossary belong to portaliq now. Its publication and catalogue requirements are carried here and built from here. A note in its proposal says so.

## Out of scope

- Machine translation. OpenRegister's `bulk-translate` exists; offering it is a later choice.
- Attachments in more than one language.
- Woo sitemaps: the Woo index takes Dutch metadata; the sitemap keeps reading the source language.
