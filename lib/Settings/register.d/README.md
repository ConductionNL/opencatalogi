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

Each fragment is a partial OpenRegister configuration document. A fragment adding
a schema should also attach it to the publication register, so it gets a magic
table and shows up in the admin settings schema selector:

```json
{
  "components": {
    "registers": {
      "publication": {
        "schemas": ["myNewThing"],
        "configuration": {
          "schemas": {
            "myNewThing": { "magicMapping": true, "autoCreateTable": true }
          }
        }
      }
    },
    "schemas": {
      "myNewThing": { "type": "object", "properties": {} }
    }
  }
}
```

Every new schema carries an `authorization` block. OpenRegister reads a schema
without one as readable and writable by everyone, anonymous callers included.
`tests/Unit/Settings/RegisterSchemaAuthorizationTest.php` fails on a schema that
has none. A flow that serves such a schema to the public (a token link, a
public form) does its own check first and then reads or writes that one object
with `_rbac: false`.

A block that names group `admin` leaves out delegated administrators. A route
behind `#[AuthorizedAdminSetting]` lets them in, so its reads and writes of such
a schema use `_rbac: false` too.

A property that must not be learnable by a reader without access takes
`"authorization": {"read": [...], "reveal": "explicit"}`. A plain read rule hides
the value from the answer, but a filter, a sort or `_search` can still confirm it.
The reveal form refuses those, and the value reads as a mask for everyone until a
single-object read asks for it, so server code that needs the value keeps a plain
read rule and reads it with `_rbac: false`.

Two safety nets cover fragments that forget:

- `SettingsService::attachOrphanSchemasToPublicationRegister()` appends any
  `components.schemas` slug that no register in the merged payload declares, with
  `magicMapping`/`autoCreateTable` defaults. An explicit config entry is never
  overwritten, so `magicMapping: false` still works.
- `RegisterSchemaLinkService::reconcile()` patches the persisted register
  after the import. Needed because OpenRegister's `importRegister()` version-gates
  the register update on `components.registers.publication.version` (unchanged
  since 0.1.0), so on an install that already has the register a non-forced import
  would otherwise leave the new schema orphaned.
