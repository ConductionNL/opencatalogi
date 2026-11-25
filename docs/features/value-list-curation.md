# Curating the national value lists

The national value lists (the Woo information categories, the DiWoo document handling and document types, the languages) are the same for every organisation. How an organisation uses them is its own: which category comes first, how it explains a category to a citizen. OpenCatalogi keeps those choices beside the list, in a value-list choice, so a refresh of the list never overwrites them and a choice never changes the list.

## What a choice holds

One `valueListChoice` object per list entry the organisation has an opinion about, in the publication register:

| Property | Meaning |
| --- | --- |
| `scheme` | The list, by the name the value-lists page uses (`informatiecategorieen`, `soortHandeling`, `documentsoorten`, ...). |
| `entry` | The URI of the list entry. |
| `order` | Where the entry is offered, lowest first. Entries without an order follow in the list's own order. |
| `localLabel` | The label the organisation shows instead of the source's. Empty shows the source's label. |
| `explanation` | What the entry means in the organisation's own words, at most 1,000 characters. |
| `hidden` | Leave the entry out of pick lists and the public filter. |
| `active` | For the organisation list: the installation publishes for this organisation. |

The code, the URI and the source's label are not in a choice. They belong to the national list and are refreshed from it. A save that sets one of them, or any property not in the table above, is refused before it is written, and the refusal names the field.

Signed-in users can read choices; only administrators write them.

## In the Woo settings

The admin settings page of OpenCatalogi has a **Category explanations** section with every information category:

- **Explanation.** Write the text and press **Save explanation**. The portal shows it where a citizen chooses the category filter.
- **Move up / Move down.** Changes the place of a category. The buttons work from the keyboard, and a screen reader hears where the category is now. The order is saved immediately.
- **Hide from pick lists.** An officer is no longer offered the category, and the public filter leaves it out. A category with publications filed under it cannot be hidden: the refusal says how many publications to move first. When those publications cannot be counted, the category is not hidden either.

Above it, the **Organisation type** section asks what kind of organisation you publish for: gemeente, provincie, waterschap, gemeenschappelijke regeling, rijksoverheid, zelfstandig bestuursorgaan or other. Choosing a type hides nothing. It shows the categories the Woo does not apply to for that type, each with its legal basis: the article (Woo, article 3.3) and the scope the article gives the category, quoted as the national TOOI list records it. Every proposed category starts checked; uncheck the ones your organisation does publish, then press **Hide the checked categories**. Each is hidden as with the switch above, so a category with publications is refused and named, and the others are still hidden.

The proposal comes from `lib/Settings/category-applicability.json`. A category is only proposed where the article names the bodies it covers and your type is not among them; nothing is proposed for "other". What you confirm is stored as your own choice, so an upgrade that changes the table does not undo it.

When the choices cannot be read, the section says so and shows the categories in the list's own order. No category is hidden because of an error.

### Organisations you publish for

The **Organisations you publish for** section lists the organisations the installation publishes for. Search the national TOOI register by name or code (`Dordrecht`, `gm0505`, `waterschap`) and switch an organisation on to activate it. Activating links the OpenRegister organisation that already carries that TOOI identifier, or creates one with the identifier and the register's name; nobody is added to it as a member. Switching it off keeps the organisation and its publications.

The register is OpenRegister's vocabulary register, filled by the daily value-list refresh with the TOOI organisation lists of municipalities, provinces, water boards, ministries and independent administrative bodies (`rwc_gemeenten_compleet`, `rwc_provincies_compleet`, `rwc_waterschappen_compleet`, `rwc_ministeries_compleet`, `rwc_zbo_compleet`). Each current organisation is offered by its preferred name and its organisation code; an organisation that has ended, and the historical versions of one, are not offered. An activated organisation that later ends stays listed, so it can be switched off.

As long as no organisation is activated, every organisation is named in the sitemap as before. Once one is activated, the DiWoo sitemap names a publisher or responsible organisation only when its TOOI identifier is activated. A publication under another organisation keeps its record, the identifier is left out, and the DiWoo validator reports `organisation is not activated` (or `responsible organisation is not activated`). The publish check reports the same gap, so a new publication cannot be published under an organisation that is not activated. When the choices cannot be read, nothing is left out.

## Where the choices show

- `GET /api/woo/categories` (signed-in users) lists the information categories that are not hidden, in the administrator's order. The pickers that read it, such as the catalogue's retention defaults, follow it.
- `GET /api/woo/categories/public` (anyone, CORS) answers the categories that are not hidden, as `{code, uri, label, explanation, order}`, in the administrator's order, with `choicesUnavailable` when the choices could not be read. This is what a portal's category filter reads.
- The sitemaps, `robots.txt` and the national-index registration are not affected by the order or by hiding: a hidden category keeps its sitemap index and its `robots.txt` line. The index is empty, because a category with publications cannot be hidden: a `sitemapindex` element in the sitemaps.org namespace without `sitemap` entries, the same document every category without publications answers. The sitemaps.org `siteindex.xsd` asks for at least one `sitemap` entry, so an empty index is well formed but not schema-valid; that is true of every empty category, hidden or not. Ordering and hiding decide what an officer is offered, never what is published.

## For integrators

| Route | Who | What |
| --- | --- | --- |
| `GET /api/woo/value-list-choices?scheme=<list>` | administrators | The list with the choices applied: `{entries: [{code, uri, sourceLabel, label, explanation, order, hidden, active, status}], choicesUnavailable}`. |
| `PUT /api/woo/value-list-choices` | administrators | Save one choice: `{scheme, entry, choice: {order?, localLabel?, explanation?, hidden?}}`. |
| `PUT /api/woo/value-list-choices/order` | administrators | Save an order: `{scheme, entries: [uri, ...]}`, first first. Only the entries whose place changed are written. |
| `GET /api/woo/categories/public` | anyone | The visible information categories with their explanation. |
| `GET /api/woo/organisation-type` | administrators | `{type, types, proposal}`: the type set (or null), the types to choose from, and the categories proposed for hiding for the type, each `{category, tooiUri, article, basis, reason}`. |
| `PUT /api/woo/organisation-type` | administrators | Set the type: `{type}`. Answers as the `GET`; an unknown type answers `400`. Nothing is hidden by it. |
| `GET /api/woo/organisations?search=<text>` | administrators | The TOOI organisations whose name or code holds the text, activated ones first, at most 50: `{results: [{uri, label, notation, active, deprecated}], total}`. Without `search`, the activated organisations. |
| `PUT /api/woo/organisations/activation` | administrators | Activate or deactivate one: `{entry: <TOOI uri>, active: true\|false}`. Answers `{entry, active, organisation, created}`, where `organisation` is the OpenRegister organisation's uuid and `created` says whether it was created. An identifier outside the registers, or an organisation that ended, answers `400`. |
| `GET /api/woo/organisations/active` | signed-in users | The activated organisations, as the search answers them. |

A refused save answers `422` with the reason; a request that names an unknown list, no entry, or a field a choice does not have answers `400`.

## Not yet

- The publication form's category, handling and document type pickers render the schema's own list and do not follow the order yet.
- The publication form's publisher and responsible-organisation pickers still offer every organisation; they do not read `GET /api/woo/organisations/active` yet. The sitemap and the publish check already follow the activated set.
