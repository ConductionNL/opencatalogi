---
kind: code
---

# Proposal: cms-handover

## Summary

Hand the public-site CMS — `menu`, `page` and the glossary — from OpenCatalogi
to Portaliq. The CRUD UI moves immediately; `MenusController`,
`PagesController` and `GlossaryController` become thin deprecated proxies to
Portaliq's content API for exactly one release, then are removed by a separate
change.

Chain link 9 of `hydra/openspec/changes/portaliq-phase-two`. Implements
ADR-086 §3 on the OpenCatalogi side.

## Motivation

OpenCatalogi owns the fleet's public-site CMS by accident of history: `menu`
and `page` OpenRegister objects, a glossary, and 31 frontend files of modals,
index views, forms and dialogs. It is a catalogue application that also happens
to be where you edit a website's navigation.

Portaliq is the app whose job that is, and it now has a `website` to scope
content to, a headless content API, and a renderer. Two apps editing the same
objects is worse than one app plus a redirect — an editor should never have to
know which of two surfaces is authoritative.

No data migrates: these are already OpenRegister objects.

## Affected Projects

- [ ] `opencatalogi` — remove the menu/page/glossary CRUD UI; reduce the three
      controllers to deprecated proxies; point admins at Portaliq.

## Design notes

**The CRUD UI moves immediately; the read path lingers.** A live deployment may
still call OpenCatalogi's endpoints — including `tilburg-woo-ui`, which ten
municipalities run. Breaking those at cutover buys nothing.

**The proxy is deliberately visible.** Deprecated, dated, and removed by its own
change, so "the proxy is still there" is a backlog item rather than something
nobody remembers.

**Catalogue-specific content is out of scope.** Catalogues, publications,
themes and directory federation stay in OpenCatalogi. What moves is the
website CMS: navigation, pages and the begrippenlijst.

## Risks

- **Ten municipal deployments read these endpoints today.** The proxy has to be
  behaviour-identical, verified against recorded responses rather than against
  the new implementation's idea of what they should be.
- **Removing the UI while the proxy remains is an asymmetry**, and asymmetries
  get forgotten. The removal change is written at the same time as this one.
- **`GlossaryController` has consumers beyond the CMS** — the manifest config
  listener and `SettingsService` both reference the glossary. Those references
  are checked before anything is deleted, not after.
