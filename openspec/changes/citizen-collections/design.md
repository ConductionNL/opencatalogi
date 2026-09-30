# Design: citizen-collections

## D1 · The `collection` schema

Register `publication`, schema slug `collection`, in the fragment `lib/Settings/register.d/citizen-collections.json`.

| Property | Type | Notes |
|---|---|---|
| `title` | string, required, max 200 | Chosen by the resident |
| `description` | string, max 2000 | Note on the whole dossier |
| `owner` | string, required | The portal `subjectRef`. Set by the server from the verified assertion, never from the body |
| `items` | array of item | See below. At most 500 |
| `share` | object or null | `{ token, createdAt }` |
| `sourceOf` | array of string | Case and ticket references started from this dossier |

Item: `{ id (uuid), publication (uuid), attachment (string or null, a Nextcloud file id), note (string), addedAt (date-time), addedBy (string: resident, or the app id that added it), title (string, optional snapshot) }`.

`authorization` allows read, create, update and delete to the `admin` group only. Employees cannot open a resident's dossier through the OpenRegister API. Portaliq reads it with its own subject-scoped reader, and opencatalogi and other apps write it as the system.

## D2 · The portal contribution

`OCA\OpenCatalogi\Portal\PortalContributionProvider` is plain: no portaliq import, no constructor dependencies. `getAudiences()` answers `['citizen', 'client']`. Any other audience gets null.

- Collection `myDossiers`: register `publication`, schema `collection`, `scopeField: owner`, fields `title`, `description`, `items`, `share`, `sourceOf`, row actions `viewDossier`, `removeFromDossier`, `noteOnDossier`, `shareDossier`, `unshareDossier`, `deleteDossier`.
- The same entry carries `itemList: { label: "Documenten", provider: "dossierItems", removeAction: "removeFromDossier" }` (hydra C7). `dossierItems(string $collectionId)` is a public method on the provider that returns `[{ id, title, url, note, public, addedAt }]`. portaliq calls it only after the resident's own scoped read of that dossier succeeded, so the method does not check the owner again.
- Action `createDossier`: `type: create`, fields `title`, `description`, `scopeField: owner`, defaults `items: []`, `sourceOf: []`.
- Action `addToDossier`: endpoint `POST /index.php/apps/opencatalogi/api/portal/collections/items`, fields `collection`, `title`, `publication`, `attachment`, `note`. Called by the public site block through `/portal/api/actions/opencatalogi/addToDossier`.
- Row actions, each an endpoint with `rowField: collection`:

| Action | Endpoint (POST) | Extra fields |
|---|---|---|
| `viewDossier` | `/index.php/apps/opencatalogi/api/portal/collections/view` | none |
| `removeFromDossier` | `/index.php/apps/opencatalogi/api/portal/collections/items/remove` | `itemId` |
| `noteOnDossier` | `/index.php/apps/opencatalogi/api/portal/collections/note` | `itemId` (empty: the dossier's own description), `note` |
| `shareDossier` | `/index.php/apps/opencatalogi/api/portal/collections/share` | none; answers `{ token, createdAt, link }`, `link` the absolute share URL portaliq shows |
| `unshareDossier` | `/index.php/apps/opencatalogi/api/portal/collections/unshare` | none |
| `deleteDossier` | `/index.php/apps/opencatalogi/api/portal/collections/delete` | none |

- Page `dossiers` ("Mijn dossiers"): a short text, the `createDossier` form and the `myDossiers` table.

The labels are Dutch, as the other providers' portal labels are.

## D3 · Endpoint security

Every `/api/portal/...` route is `#[PublicPage]` + `#[NoCSRFRequired]` and accepts only a valid `X-Portal-Subject` assertion. `PortalAssertionVerifier` is the fleet's reference verifier (petstore, filinq), copied: HS256 only, constant-time signature check, `use: assertion`, `iss: portaliq`, `exp` in the future, `iat` plausible, `sub` non-empty. The secret is portaliq's `jwt_signing_secret`, else the instance secret, the same derivation portaliq uses. No valid assertion: 401.

The actor is the assertion's `sub`. Every read of a collection is by id, as the system, and then `owner === sub` is compared. A mismatch answers 404, the same as an id that does not exist.

## D4 · Public-ness

A publication is public when `publicationDate <= now` and `depublicationDate` is empty or later (the rule `PublicationQueryService::isObjectPublic()` already implements). The publication is read inside OpenRegister's anonymous scope, so a publication an anonymous visitor cannot read is not public here either.

- Adding an item requires a public publication. A non-public or unknown one answers 404.
- The owner's view (`viewDossier`) returns every item with `public: true|false`, the live title when public, else the stored snapshot.
- The shared view returns only items whose publication is public now.

## D5 · Share token

`share.token` is `{collection uuid}.{48 hex characters}` (192 random bits from `random_bytes`). The shared route parses the uuid, reads that collection as the system, and compares the whole token with `hash_equals`. Any failure answers 404. Revoking sets `share` to null, so the old link answers 404. Sharing again makes a new token.

The shared answer is `{ title, description, items: [{ id, publication, attachment, note, addedAt, title, url }] }`. It has no `owner`, `share` or `sourceOf`.

## D6 · Account removal

Portaliq has no account-removal event. `PortalSelfServiceService::removeAccount()` updates the `portalAccount` object in register `portaliq` to `status: removed`. `PortalAccountRemovedListener` listens for OpenRegister's `ObjectUpdatedEvent`. When the object is in register `portaliq`, schema `portalAccount`, the new status is `removed` and the old one was not, it deletes every `collection` and `savedSearch` whose `owner` is that account's `subjectRef`. Failures are logged and never fail the save.

The register and schema are matched by slug through OpenRegister's mappers, so no portaliq class is needed.

## Risks

- A resident with many dossiers. Capped at 50 dossiers per owner and 500 items per dossier; a request over the cap answers 422.
- The portal passes `collection` in the body of `addToDossier`, which is not row-proven by portaliq. opencatalogi's own owner check is the guard, and it answers 404 on a mismatch.
