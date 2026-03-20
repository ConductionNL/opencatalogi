# Register Content Internationalization

## Purpose
Enable multi-language support for OpenCatalogi's register objects, allowing users to view and manage publication and catalog content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`).

OpenCatalogi is the most translation-heavy app in the Conduction suite. Publications are the primary use case for multi-language content, as catalogs often serve both Dutch-speaking and international audiences, particularly under EU Single Digital Gateway (SDG) requirements.

## Context
Dutch municipalities increasingly serve multilingual populations and must comply with EU SDG requirements to provide certain information in multiple EU languages. OpenCatalogi's catalog websites are the public-facing interface, making content internationalization essential. This spec covers the content layer (register object field translations), not the UI string layer (which is handled by Nextcloud's standard l10n/gettext system and the separate `i18n-opencatalogi` spec).

**Relation to existing specs:**
- OpenRegister `register-i18n`: Provides the foundation -- translatable field flag on schema properties, language-tagged storage, Accept-Language API negotiation
- OpenCatalogi `i18n-opencatalogi`: Handles UI string translations (Vue component labels, button text, etc.)
- This spec focuses on register content: publication titles, catalog descriptions, page content, etc.

**Relation to existing OpenCatalogi entities:**
- **Publication**: Title, summary, description, content -- the highest-priority translation targets
- **Catalog**: Title, description -- determines how the catalog appears to international visitors
- **Page**: Title, content -- static pages within catalogs (about, contact, FAQ)
- **Theme**: Title, description -- categorization labels
- **Menu item**: Label -- navigation elements
- **Glossary item**: Term, definition -- domain-specific terminology
- **Organization**: Title, description -- public-facing organization names

## ADDED Requirements

### Requirement: Publication fields MUST support multi-language content
Publication objects MUST store and serve multi-language content for their primary text fields.

#### Scenario: Publication with Dutch and English titles
- GIVEN a publication with translations:
  - `title.nl`: "Jaarverslag 2024"
  - `title.en`: "Annual Report 2024"
- WHEN the API is called with `Accept-Language: en`
- THEN the response MUST contain `title: "Annual Report 2024"`
- AND the `Content-Language` header MUST be "en"

#### Scenario: Publication summary in requested language
- GIVEN a publication with `summary.nl` and `summary.en` translations
- WHEN the API is called with `?lang=nl`
- THEN the `summary` field MUST contain the Dutch text
- AND the `?lang` parameter MUST override the `Accept-Language` header

#### Scenario: Publication content falls back to default language
- GIVEN a publication with `content.nl` but no `content.en`
- WHEN the API is called with `Accept-Language: en`
- THEN the `content` field MUST fall back to the Dutch text
- AND the response MUST include a fallback indicator (e.g., `_contentLanguage: "nl"` or `_fallback: true` on the field)

#### Scenario: All publication text fields are translatable
- GIVEN the publication schema in OpenRegister
- THEN the following fields MUST have the `translatable` flag set:
  - `title`
  - `summary`
  - `description`
  - `content`
- AND non-text fields (status, dates, attachments, UUIDs) MUST NOT be translatable

#### Scenario: Publication search returns results in requested language
- GIVEN publications with Dutch and English titles
- WHEN a search is performed with `?lang=en&q=annual`
- THEN publications matching "annual" in their English title MUST be returned
- AND the results MUST display English titles and summaries

### Requirement: Catalog fields MUST support multi-language content
Catalog objects MUST store and serve multi-language content for display fields.

#### Scenario: Catalog with multilingual title and description
- GIVEN a catalog with:
  - `title.nl`: "Publicaties Gemeente Utrecht"
  - `title.en`: "Publications Municipality of Utrecht"
  - `description.nl`: "Alle openbare publicaties van de gemeente"
  - `description.en`: "All public publications of the municipality"
- WHEN the catalog website is accessed with `Accept-Language: en`
- THEN the page title MUST show "Publications Municipality of Utrecht"
- AND the description MUST show the English text

#### Scenario: Catalog default language configuration
- GIVEN a catalog with `defaultLanguage: "nl"`
- AND the catalog has content in nl, en, and de
- WHEN a visitor accesses the catalog without language preference
- THEN the content MUST be served in Dutch (the configured default)

#### Scenario: Catalog with only one language
- GIVEN a catalog with content only in Dutch
- WHEN the API is called with `Accept-Language: en`
- THEN the Dutch content MUST be served as fallback
- AND the response MUST indicate the content is in Dutch (not English)

### Requirement: Page and theme fields MUST support multi-language content
Static pages, themes, and menu items MUST store and serve multi-language content.

#### Scenario: Page with translated content
- GIVEN a page with:
  - `title.nl`: "Over ons"
  - `title.en`: "About us"
  - `content.nl`: "Welkom bij de gemeente..."
  - `content.en`: "Welcome to the municipality..."
- WHEN the page is accessed with `Accept-Language: en`
- THEN both title and content MUST be served in English

#### Scenario: Theme with translated title
- GIVEN a theme with `title.nl: "Bestuur en organisatie"` and `title.en: "Governance and organization"`
- WHEN the themes API is called with `?lang=en`
- THEN the theme title MUST be "Governance and organization"

#### Scenario: Menu item with translated label
- GIVEN a menu item with `label.nl: "Zoeken"` and `label.en: "Search"`
- WHEN the menus API is called with `Accept-Language: en`
- THEN the menu label MUST be "Search"

#### Scenario: Glossary item with translated term and definition
- GIVEN a glossary item with:
  - `term.nl`: "Weigeringsgrond"
  - `term.en`: "Refusal ground"
  - `definition.nl`: "Wettelijke grond voor het weigeren van openbaarmaking"
  - `definition.en`: "Legal ground for refusing disclosure"
- WHEN the glossary API is called with `?lang=en`
- THEN both term and definition MUST be in English

#### Scenario: Organization with translated title
- GIVEN an organization with `title.nl: "Gemeente Utrecht"` and `title.en: "Municipality of Utrecht"`
- WHEN the organization data is retrieved with `Accept-Language: en`
- THEN the title MUST be "Municipality of Utrecht"

### Requirement: Language fallback chain MUST be configurable and predictable
The system MUST follow a defined fallback chain when content is not available in the requested language.

#### Scenario: Fallback chain order
- GIVEN a publication with content in nl and de (but not en)
- WHEN the API is called with `Accept-Language: en`
- THEN the fallback chain MUST be: en (requested) -> app default -> nl -> en -> first available
- AND since nl is available, the Dutch content MUST be returned

#### Scenario: Catalog-specific default language in fallback
- GIVEN a catalog with `defaultLanguage: "de"` and a publication with content in de and fr
- WHEN the publication API is called with `Accept-Language: en`
- THEN the fallback chain MUST be: en -> de (catalog default) -> nl -> en -> first available
- AND since de is available via the catalog default, German content MUST be returned

#### Scenario: Fallback indicator in API response
- GIVEN content served via fallback (not in the requested language)
- WHEN the response is generated
- THEN the `Content-Language` header MUST indicate the actual language served
- AND each field that fell back MUST have a `_lang` suffix or metadata indicating the actual language
- AND the frontend MUST be able to detect and display a fallback notice

#### Scenario: All translations unavailable
- GIVEN a publication with no translations at all (legacy data)
- WHEN the API is called with any `Accept-Language` header
- THEN the raw (untranslated) content MUST be served
- AND the `Content-Language` header MUST be omitted or set to the system default

#### Scenario: Accept-Language header with quality values
- GIVEN a request with `Accept-Language: de;q=0.9, en;q=0.8, nl;q=0.7`
- AND a publication with content in nl and en
- WHEN the language is negotiated
- THEN English content MUST be served (highest priority available language)
- AND the `Content-Language` header MUST be "en"

### Requirement: Frontend MUST provide language switching
The frontend MUST allow users to switch languages for content display without page reload.

#### Scenario: Language selector on publication detail page
- GIVEN a publication with translations in nl, en, and de
- WHEN the user views the publication detail page
- THEN a language selector MUST be displayed showing nl, en, de as options
- AND the current language MUST be highlighted
- AND clicking a different language MUST update the content without page reload

#### Scenario: Language selection persists across navigation
- GIVEN the user selects English on a publication detail page
- WHEN the user navigates to another publication
- THEN the new publication MUST also be displayed in English (if available)
- AND the language preference MUST persist during the session

#### Scenario: Public catalog view shows prominent language switcher
- GIVEN a public catalog website with content in multiple languages
- WHEN a visitor loads the catalog homepage
- THEN a language switcher MUST be visible in the header or navigation area
- AND switching language MUST update the catalog title, description, navigation labels, and publication listings

#### Scenario: Language selector only shows available languages
- GIVEN a publication with translations in nl and en only
- WHEN the language selector is displayed
- THEN only nl and en MUST be shown as options
- AND languages without translations MUST NOT appear

#### Scenario: Language switching triggers API call with lang parameter
- GIVEN the user clicks "en" in the language selector
- WHEN the content refreshes
- THEN the API call MUST include `?lang=en`
- AND the response MUST be in English

### Requirement: API MUST support language negotiation
All content-serving API endpoints MUST support language selection via headers and query parameters.

#### Scenario: Accept-Language header is respected
- GIVEN `GET /api/{catalogSlug}` with `Accept-Language: en`
- WHEN the publication list is returned
- THEN all translatable fields MUST be in English (where available)
- AND the `Content-Language` response header MUST be "en"

#### Scenario: Query parameter overrides header
- GIVEN `GET /api/{catalogSlug}?lang=de` with `Accept-Language: en`
- WHEN the publication list is returned
- THEN all translatable fields MUST be in German (where available)
- AND the `?lang` parameter MUST take precedence over the header

#### Scenario: Search API supports language-filtered search
- GIVEN `GET /api/search?q=rapport&lang=nl`
- WHEN the search executes
- THEN results MUST be searched in Dutch content fields
- AND result snippets MUST be in Dutch
- AND the response MUST indicate the search language

#### Scenario: Listing endpoint returns translated content
- GIVEN `GET /api/listings?lang=en`
- WHEN the listing index is returned
- THEN listing titles and descriptions MUST be in English where available
- AND listings without English translations MUST show fallback content with indicator

#### Scenario: Glossary endpoint returns translated terms
- GIVEN `GET /api/glossary?lang=en`
- WHEN the glossary list is returned
- THEN terms and definitions MUST be in English where available

### Requirement: Translation management MUST be integrated in admin UI
Admin users MUST be able to manage translations for all content types through the OpenCatalogi admin interface.

#### Scenario: Publication edit form shows translation tabs
- GIVEN an admin editing a publication
- WHEN the edit form loads
- THEN a language tab bar MUST be displayed above the translatable fields
- AND tabs for configured languages (e.g., nl, en) MUST be shown
- AND switching tabs MUST show the translation for that language

#### Scenario: Adding a new language translation
- GIVEN an admin editing a publication with only Dutch content
- WHEN the admin clicks "Add translation" and selects English
- THEN an English language tab MUST appear
- AND the translatable fields MUST be empty (ready for English input)
- AND saving MUST store both the Dutch and English versions

#### Scenario: Translation completeness indicator
- GIVEN a publication with Dutch and English translations where English `description` is missing
- WHEN the admin views the publication in the list
- THEN a translation completeness indicator MUST show (e.g., "2/3 fields translated for en")

#### Scenario: Bulk translation status overview
- GIVEN 100 publications in a catalog
- WHEN the admin views the publications list
- THEN a column or filter MUST show translation status per publication
- AND the admin MUST be able to filter to "missing English translation" to find gaps

### Requirement: Schema translatable flag MUST be set on correct fields
OpenRegister schema properties for OpenCatalogi entities MUST have the `translatable` flag correctly configured.

#### Scenario: Publication schema translatable fields
- GIVEN the publication schema in OpenRegister
- THEN these properties MUST have `translatable: true`:
  - title, summary, description, content
- AND these properties MUST NOT have `translatable: true`:
  - id, uuid, status, published, modified, catalog, attachments, themes

#### Scenario: Catalog schema translatable fields
- GIVEN the catalog schema in OpenRegister
- THEN these properties MUST have `translatable: true`:
  - title, description
- AND slug, defaultLanguage, organization, and other structural fields MUST NOT be translatable

#### Scenario: Page schema translatable fields
- GIVEN the page schema in OpenRegister
- THEN title and content MUST have `translatable: true`
- AND slug, order, catalog, and other structural fields MUST NOT be translatable

#### Scenario: Theme schema translatable fields
- GIVEN the theme schema in OpenRegister
- THEN title and description MUST have `translatable: true`

#### Scenario: Menu item schema translatable fields
- GIVEN the menu item schema in OpenRegister
- THEN label MUST have `translatable: true`
- AND href, order, parent, and other structural fields MUST NOT be translatable

### Requirement: Sitemap MUST support multi-language entries
The sitemap generator MUST include language-specific entries for published catalogs with multi-language content.

#### Scenario: Sitemap includes hreflang annotations
- GIVEN a catalog with publications in nl and en
- WHEN the sitemap is generated
- THEN each publication URL MUST include `<xhtml:link rel="alternate" hreflang="nl">` and `<xhtml:link rel="alternate" hreflang="en">` elements
- AND the URLs MUST include the `?lang=` parameter for each language

#### Scenario: Sitemap only includes available languages
- GIVEN a publication with content only in Dutch
- WHEN the sitemap is generated
- THEN only the Dutch URL MUST be included (no hreflang for other languages)

#### Scenario: Sitemap respects catalog default language
- GIVEN a catalog with `defaultLanguage: "nl"` and publications in nl and en
- WHEN the sitemap is generated
- THEN the default (no lang parameter) URL MUST serve Dutch content
- AND an additional URL with `?lang=en` MUST be included for the English version

### Requirement: Federation MUST propagate language metadata
When publications are federated across OpenCatalogi instances, language metadata MUST be preserved.

#### Scenario: Federated publication includes available languages
- GIVEN a publication with translations in nl and en
- WHEN the publication is served via the federation API
- THEN the response MUST include an `_availableLanguages` field listing ["nl", "en"]
- AND the content MUST respect the Accept-Language header

#### Scenario: Federated search respects language parameter
- GIVEN a federated search across 3 OpenCatalogi instances
- WHEN the search is performed with `?lang=en`
- THEN results from all instances MUST be in English where available
- AND results MUST indicate the actual language served

#### Scenario: Directory listing includes supported languages
- GIVEN an OpenCatalogi instance with catalogs in nl, en, and de
- WHEN the directory listing is served
- THEN the directory entry MUST include `supportedLanguages: ["nl", "en", "de"]`

## MODIFIED Requirements

_None -- this is a new capability._

## REMOVED Requirements

_None._

## Current Implementation Status
- **Not implemented**: No multi-language content support exists in OpenCatalogi. All content is stored in a single language (typically Dutch). Publications, catalogs, pages, themes, and all other content types are single-language.
- **Building blocks that exist**:
  - OpenCatalogi API endpoints for all content types (PublicationsController, CatalogiController, PagesController, ThemesController, MenusController, GlossaryController)
  - SearchService and SearchController for search operations
  - SitemapService for sitemap generation
  - DirectoryService and FederationController for federation
  - All content types stored as OpenRegister objects (extensible schema)
- **Key gaps**:
  - No `translatable` flag on any OpenCatalogi schema properties
  - No Accept-Language header parsing in any controller
  - No `?lang=` query parameter support
  - No Content-Language response header
  - No fallback chain implementation
  - No language selector Vue component
  - No translation management UI in admin interface
  - No translation completeness tracking
  - No hreflang annotations in sitemap
  - No language metadata in federation responses
- **Foundation dependency**: OpenRegister's `register-i18n` spec MUST be implemented first, providing the translatable field flag, language-tagged storage, and base Accept-Language negotiation

## Standards & References
- OpenRegister register-i18n spec (foundation)
- BCP 47 language tags (nl, en, de, fr, etc.)
- W3C Internationalization best practices
- Nextcloud l10n framework (for UI strings -- separate from register content i18n)
- WCAG 2.1 SC 3.1.1 (Language of Page) and SC 3.1.2 (Language of Parts)
- EU Single Digital Gateway (SDG) Regulation (EU) 2018/1724 -- publications may need to be available in multiple EU languages
- RFC 7231 Section 5.3.5 (Accept-Language header)
- RFC 7231 Section 3.1.3.2 (Content-Language header)
- Google Search hreflang specification for multi-language sitemaps

## Dependencies
- OpenRegister `register-i18n` spec (MUST be implemented first)
- OpenCatalogi schema definitions in OpenRegister (need translatable flags)
- Nextcloud IRequest for Accept-Language header access
- SearchService/ElasticSearchService for language-aware search
- SitemapService for hreflang generation
- FederationController for language metadata propagation
