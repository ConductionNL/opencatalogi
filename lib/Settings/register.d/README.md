# Modular register fragments (ADR-037)

Drop one `*.json` fragment per OpenSpec change here instead of editing the
monolithic `lib/Settings/publication_register.json`. At import time
`SettingsService::loadSettings()` deep-merges every `register.d/*.json` (sorted
by filename) onto the base register before handing it to OpenRegister's
`importFromApp`.

Why: concurrent same-app builds each add their own register schemas/paths to a
disjoint fragment file, so they never collide on the shared register monolith.

Merge semantics (`deepMergeConfig`):

- Associative objects (OpenAPI `components.schemas`, `paths`, …) union by key,
  recursing on shared keys.
- List arrays are concatenated.
- Scalars in a fragment overwrite the base.

The combined fragment hash is folded into the import version
(`<appVersion>+frag.<hash>`) so OpenRegister re-imports whenever a fragment
changes.

Each fragment is a partial OpenRegister configuration document, e.g.:

```json
{
  "components": {
    "schemas": {
      "MyNewThing": { "type": "object", "properties": {} }
    }
  }
}
```
