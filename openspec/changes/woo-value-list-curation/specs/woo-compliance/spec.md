---
status: proposed
---

# Woo compliance

## ADDED Requirements

### Requirement: The organisation's own text sits beside the normative entry (REQ-WVL-001)

A schema `valueListChoice` SHALL hold `scheme` (URI), `entry` (concept URI), `order` (integer), `localLabel`, `explanation` (at most 1,000 characters), `hidden` (boolean) and `active` (boolean), readable by authenticated users and writable by administrators. A save that sets a property not in this list SHALL be refused. `OCA\OpenCatalogi\Service\Woo\ValueListView::entries(string $scheme): array` SHALL answer each concept of the scheme from OpenRegister's vocabulary register merged with its choice, as `{code, uri, sourceLabel, label, explanation, order, hidden, active, status}`, where `label` is `localLabel` when set and `sourceLabel` otherwise. A refresh of the scheme (`TooiSchemeRefresh`) SHALL NOT read or write `valueListChoice`. When choices cannot be read, `entries()` SHALL answer the concepts in source order with no choice applied and a flag `choicesUnavailable`.

#### Scenario: A refresh keeps the local label
<!-- @e2e exclude Background refresh; proven by ValueListViewTest::testARefreshChangesTheSourceLabelAndKeepsTheLocalOne, which fails on today's code because no overlay exists. -->

- **GIVEN** a category whose source label is "Bereikbaarheidsgegevens" and whose local label is "Contact en bereikbaarheid"
- **WHEN** the source renames it and the refresh runs
- **THEN** `sourceLabel` is the new name and `label` is still "Contact en bereikbaarheid"

#### Scenario: A normative field cannot be edited
<!-- @e2e exclude Pre-save refusal; proven by ValueListChoiceListenerTest::testANormativeFieldIsRefused, built on the real ObjectUpdatingEvent. -->

- **WHEN** an administrator saves a choice that sets `code` or `uri`
- **THEN** the save is refused naming the field

### Requirement: The administrator's order drives every pick list (REQ-WVL-002)

The Woo settings SHALL show each list (information categories, soort handeling, documentsoorten) with Move up and Move down buttons per entry, keyboard operable with a live-region announcement, writing `order`. `GET /api/woo/categories`, the publication form's category, handling and document type pickers, and `GET /api/woo/categories/public` SHALL list entries by `order`, then by source order.

#### Scenario: An administrator puts the most used category first

- **GIVEN** the 17 categories in source order
- **WHEN** an administrator moves "Subsidieverplichtingen anders dan met beschikking" to the top with the keyboard and saves
- **THEN** an officer creating a publication sees it first in the category picker

### Requirement: A category explanation is served publicly (REQ-WVL-003)

`GET /api/woo/categories/public` (public, CORS) SHALL answer the categories that are not hidden as `{code, uri, label, explanation, order}`. The Woo settings SHALL let an administrator write the explanation per category. This is the contract the portal's category filter reads.

#### Scenario: The explanation is served publicly
<!-- @e2e exclude Public API contract for the portal; proven by PublicCategoriesTest::testTheExplanationIsServedAndHiddenCategoriesAreNot, which fails on today's code because the route does not exist. -->

- **GIVEN** an explanation for "Convenanten" and one hidden category
- **WHEN** an anonymous client calls `GET /api/woo/categories/public`
- **THEN** "Convenanten" carries the explanation and the hidden category is absent

### Requirement: Categories that do not apply are hidden, and their sitemap stays valid (REQ-WVL-004)

The Woo settings SHALL let an administrator set `woo_organisation_type` (gemeente, provincie, waterschap, gemeenschappelijke regeling, rijksoverheid, zelfstandig bestuursorgaan, overig). `lib/Settings/woo-category-applicability.json` SHALL propose, per type, the categories that do not apply, each with its legal basis in the Woo; an entry without a cited basis SHALL NOT be proposed. On choosing a type the page SHALL show the proposal for the administrator to confirm or change per category, writing `hidden`. A hidden category SHALL be absent from officer pick lists and from `GET /api/woo/categories/public`. Its sitemap index SHALL still be served, valid against the sitemap schema, with no entries. Hiding a category with publications filed under it SHALL be refused, naming the count.

#### Scenario: A water board hides the categories it never publishes

- **GIVEN** an administrator of a water board
- **WHEN** they set the organisation type to waterschap and confirm the proposal
- **THEN** the proposed categories are hidden from the officer picker

#### Scenario: A hidden category's sitemap stays valid and empty
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testAHiddenCategoryServesAnEmptyValidIndex, which fails on today's code because no category can be hidden. -->

- **GIVEN** a hidden category with no publications
- **WHEN** its sitemap index is requested
- **THEN** it answers a valid, empty sitemap index

#### Scenario: A category in use cannot be hidden
<!-- @e2e exclude Pre-save refusal; proven by ValueListChoiceListenerTest::testHidingACategoryInUseIsRefused. -->

- **GIVEN** a category with three publications
- **WHEN** an administrator hides it
- **THEN** the save is refused naming three publications

### Requirement: The administrator activates the organisations the installation publishes for (REQ-WVL-005)

The Woo settings SHALL let an administrator search the TOOI organisation scheme in OpenRegister's vocabulary register and activate entries, writing `active` on their choice. Activating SHALL link the OpenRegister organisation that carries that TOOI identifier, or create one with the TOOI identifier and the source name. The publisher and responsible-organisation pickers SHALL offer only organisations whose TOOI identifier is activated, to non-administrators and administrators alike. `SitemapService` SHALL emit a publisher or responsible organisation only when its TOOI identifier is activated, and report the rest through the DiWoo validator.

#### Scenario: Only activated organisations are offered

- **GIVEN** an installation that activated "Gemeente Dordrecht" and "Gemeente Zwijndrecht" out of the TOOI register
- **WHEN** an officer opens the publisher picker
- **THEN** exactly those two are offered

#### Scenario: A publication under an organisation that was not activated
<!-- @e2e exclude Sitemap XML contract; proven by SitemapServiceTest::testAPublisherNotActivatedIsOmittedAndReported. -->

- **GIVEN** a legacy publication whose organisation's TOOI identifier is not activated
- **WHEN** its sitemap page is rendered
- **THEN** no publisher is emitted for it and the validator reports it

## MODIFIED Requirements

### Requirement: The information categories are data, not code (REQ-WIC-001)

Adding an information category SHALL need no code change. `WooCategoryRegistry` SHALL serve the 18 members of the `scw_woo_informatiecategorieen` waardelijst together with every category stored as an `informationCategory` object, and the sitemap routes, the `robots.txt` lines, the national-index registration request and `GET /api/woo/categories` SHALL all be built from that one set. The sitemap file name of a category SHALL be `sitemapindex-diwoo-{code}.xml`, because the national harvester matches that name. Every category of the set, hidden or not, SHALL keep its sitemap index; a hidden category's index SHALL be valid and empty (REQ-WVL-004, decision D5). Officer pick lists and `GET /api/woo/categories/public` SHALL leave hidden categories out and follow the administrator's order (REQ-WVL-002).

#### Scenario: An operator adds a category of their own

- **GIVEN** an admin stores an `informationCategory` with code `aanbestedingen` that publishes under `infocat018`
- **WHEN** the registry is read
- **THEN** `sitemapindex-diwoo-aanbestedingen.xml` is served, appears in `robots.txt` and is offered by `GET /api/woo/categories`
- **AND** no code was changed to get it

> @e2e exclude Server-side registry over an OpenRegister schema with no browser surface of its own; PHPUnit (WooCategoryRegistryTest) hands the registry the stored rows where OpenRegister hands them in.

#### Scenario: The art 3.1 category is one of the bundled members

- **GIVEN** a publication filed under `infocat018`
- **WHEN** the harvester requests `sitemapindex-diwoo-infocat018.xml`
- **THEN** the publication is listed with the TOOI URI `…/kern/c_816e508d`

> @e2e exclude The emitted XML carries this; PHPUnit (SitemapServiceTest, WooCategoryRegistryTest) asserts the file name and the URI.

#### Scenario: The set is stable for the harvester
<!-- @e2e exclude Sitemap and robots contract; proven by WooCategoryRegistryTest::testAHiddenCategoryKeepsItsSitemapIndexAndRobotsLine. -->

- **GIVEN** one hidden category
- **WHEN** `robots.txt` and that category's sitemap index are requested
- **THEN** `robots.txt` still names the index and the index is valid and empty
