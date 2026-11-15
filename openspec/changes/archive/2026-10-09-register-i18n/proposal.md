# Proposal: register-i18n

> Spec round part 3 (7 October 2026): the publication and catalogue part of this change is carried and built by `publication-translations`, on OpenRegister's translation machinery. Pages, menus, themes and glossary moved to portaliq (ADR-086). Build nothing for publications or catalogues from this change.

## Summary
Add multi-language content support to OpenCatalogi, enabling publications, catalogs, pages, themes, menus, and glossary entries to be stored and served in multiple languages.

## Motivation
Dutch government organizations serve diverse populations and must comply with accessibility requirements including multilingual content. Currently all OpenCatalogi content is stored in a single language.

## Scope
- Language-tagged fields for all content types
- Language negotiation in API responses
- Translation management UI
- Search across languages
- Sitemap generation per language
