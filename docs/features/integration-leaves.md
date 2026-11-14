# Integration leaves: mail intake and publication planning

OpenCatalogi links Nextcloud apps to its publications through OpenRegister's
integration leaves. A leaf is declared, never programmed: a `configuration` key on a
schema in the register (`lib/Settings/register.d/leaf-integrations.json`) and, where
the leaf renders in OpenCatalogi, an integration widget in `src/manifest.json`.
OpenCatalogi ships no leaf code of its own; the providers, the Mail sidebar and the
widget rendering are OpenRegister's.

## Mail: a request by email becomes a draft publication

Publication requests and source material often arrive in a shared mailbox. With the
Nextcloud Mail app enabled, a desk employee opens the email, uses **create
publication** in the Mail sidebar, and gets a new publication filled in from the email:

| Publication property | Filled with |
|---|---|
| `title` | the subject |
| `summary` | the start of the message |
| `description` | "Received by email from *name* <*address*> on *date*." followed by the start of the message |

Nothing else is set. In particular the email never sets `publicationDate`,
`depublicationDate`, `status`, the organization, the themes or any retention field.

**The publication arrives as a draft.** Whether the public can read a publication is
decided by its `publicationDate`; a publication without one is invisible to anonymous
visitors, absent from the sitemap and not offered to harvesters. An emailed request
therefore never goes public by itself: an editor classifies it (organization, themes)
and publishes it with the normal editorial act, exactly as for a publication created
by hand. Creating a publication from an email is always one person acting on one
email; nothing turns mail into publications automatically.

## Calendar: planning, not publishing

The publication page carries a **Planning (does not publish)** panel. Editors link
calendar events to the publication there — "publish the council decisions on Friday",
"depublish the draft after recess" — so the plan sits next to the record.

The plan and the fact are kept apart on purpose. The panel never reads or writes
`publicationDate`, `depublicationDate` or `status`: moving, linking or deleting an
event changes nothing about whether the publication is public. Publishing and
depublishing stay the guarded edits of those properties they always were. If the
calendar says Friday and nobody publishes, the publication stays a draft.

## Which leaves are on, and which are off

| Leaf | Where | Status |
|---|---|---|
| Files | Publication: Attachments | On (unchanged) |
| Photos | Publication: Images; Organization: Logo & images | On (unchanged) |
| Bookmarks | Publication: Links | On (unchanged) |
| Mail | Nextcloud Mail sidebar, on `publication` | On |
| Calendar | Publication: Planning (does not publish) | On |
| Forms, Contacts | Organization | Not declared: the organization schema is OpenRegister's shared one, not part of OpenCatalogi's register, so OpenCatalogi cannot add leaves to it |
| Mail as a panel on the publication page | — | Off: the sidebar intake is the workflow that exists |
| Deck, Talk, Polls | — | Off: publishing is not card- or conversation-shaped here |
| Maps | — | Off: no schema has a location |
| Shares, Collectives, Notes, Activity, Time tracker, Analytics, Tasks | — | Off: nobody asked for them, and every panel costs page space |
| Mail intake on documents, catalogues or pages | — | Off: one intake first |

Every leaf is optional at runtime: when its Nextcloud app is not installed, its panel
or sidebar button simply does not appear.
