# Proposal: example-publications-are-demo-data

## Why

The bundled publication register, imported on every install, carried two example publications ("Voorbeeld: Jaarverslag 2024", "Voorbeeld: Conduction Kwartaalrapport Q1 2026"), their two example documents and one depublication. On a real organisation's instance those rows are public and searchable. In the Zuiddrecht go-live rehearsal on 2026-10-15 an example publication appeared in the municipality's Woo search on its portal.

ADR-111 already decides this: demo objects are installed on request only, never on install, because demo rows on a production instance are indistinguishable from real records to everyone who did not install them. The example rows were the one place where opencatalogi still seeded them on install.

## What changes

- The five example rows move from `lib/Settings/publication_register.json` to the demo dataset `lib/Settings/opencatalogi_mock_register.json`, which the setup walkthrough or `occ` installs on request.
- Their texts say they are demo data, not that they arrive with a fresh install.
- REQ-PPW-003's withdrawn example (so Publish again can be tried) is part of the demo data.

## Not changed

- The catalogue, pages, menus and theme the register ships.
- Rows already imported on an existing instance: an administrator removes them by hand if they are not wanted. Nothing is deleted automatically, because an organisation may have kept or edited them.
