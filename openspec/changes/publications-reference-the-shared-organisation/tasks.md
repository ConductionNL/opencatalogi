# Tasks

## 1. Point at the shared organisation

- [x] 1.1 `publication.organization` and `catalog.organization` `$ref`
      `nc-organisation`.
- [x] 1.2 Remove the `organization` schema from the register descriptor.
- [x] 1.3 Remove the SECOND copy from the `ooapi-catalog-publication` fragment.
      Two descriptors shipped the same slug.
- [x] 1.4 Bump both `info.version` and the register's version, or the
      version-gated import never applies.

## 2. Configuration

- [x] 2.1 Drop `organization` from the object types resolved out of this app's
      own import result. The schema is not ours to resolve.
- [x] 2.2 Resolve `organization_source` / `_register` / `_schema` from
      OpenRegister's directory register instead.
- [x] 2.3 Fail soft when OpenRegister or the projection is absent: leave the
      keys unset rather than failing an import.

## 3. Frontend

- [x] 3.1 Nothing. `getCollection('organization')` resolves through the three
      config keys and nothing else, so repointing them moves the picker onto the
      shared organisation with no Vue change. Verified by reading the store's
      resolution, not assumed.

## 4. Retirement on an existing instance

Same two-command shape as `document`, and the second refuses if the first was
not run:

1. `occ openregister:organisations:adopt --register publication` — adopts the
   leaf rows into OpenRegister's Organisation, preserving each uuid so stored
   references keep resolving, and recording a merge where the same legal entity
   already exists.
2. `occ openregister:schemas:prune-retired --app opencatalogi --slug organization --apply`

- [ ] 4.1 Run on the fleet. The descriptor change alone removes nothing:
      `ImportHandler` unions schema ids.
