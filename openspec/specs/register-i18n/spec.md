# Register Content Internationalization

## Purpose
Enable multi-language support for OpenCatalogi's register objects, allowing users to view and manage publication and catalog content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`).

OpenCatalogi is the most translation-heavy app in the Conduction suite. Publications are the primary use case for multi-language content, as catalogs often serve both Dutch-speaking and international audiences, particularly under EU Single Digital Gateway (SDG) requirements.

## Requirements

### REQ-I18N-001: Language-Tagged Fields
The following OpenCatalogi-specific fields MUST support multi-language content via OpenRegister's `translatable` flag:

**Publications:**
- `title` — publication title, the primary display field in listings and search results
- `summary` — short description shown in list views and search snippets
- `description` — full publication description
- `content` — the main body content of the publication

**Catalogs:**
- `title` — display name of the catalog
- `description` — explanation of the catalog's scope and purpose

**Pages:**
- `title` — page title displayed in navigation and headings
- `content` — full page body content (may include rich text/markdown)

**Themes:**
- `title` — display name of the theme/category
- `description` — explanation of what the theme covers

**Menu items:**
- `label` — display text of the menu item shown in navigation

**Glossary items:**
- `term` — the glossary term itself
- `definition` — explanation/definition of the term

**Organizations:**
- `title` — display name of the organization
- `description` — description of the organization's role and responsibilities

### REQ-I18N-002: Language Fallback Chain
- MUST follow the Nextcloud user's language preference
- MUST fall back: user language -> app default language -> nl -> en -> first available
- MUST display fallback indicator when showing non-preferred language
- For public-facing catalog pages, the fallback chain MUST also respect the catalog's configured default language

### REQ-I18N-003: Frontend Language Switching
- MUST show language selector on detail pages when translated content exists
- MUST preserve current language selection across navigation within the app
- Language switching MUST NOT require page reload
- Public catalog views MUST show a prominent language switcher when multiple languages are available
- Search results MUST indicate which language a result is displayed in when it differs from the requested language

### REQ-I18N-004: API Language Support
- API responses MUST accept `Accept-Language` header
- API responses MUST include `Content-Language` header
- `?lang=nl` query parameter MUST override Accept-Language
- Listing endpoints MUST return content in requested language with fallback
- The public search API (`/api/search`) MUST support language-filtered search, returning results in the requested language
- The catalog's public-facing API MUST support language negotiation for all publication endpoints

## Current Implementation Status
Not implemented. No multi-language content support exists in OpenCatalogi. All content is stored in a single language (typically Dutch). Publications, catalogs, pages, themes, and all other content types are single-language.

## Standards & References
- OpenRegister register-i18n spec (foundation)
- BCP 47 language tags (nl, en, de, fr, etc.)
- W3C Internationalization best practices
- Nextcloud l10n framework (for UI strings -- separate from register content i18n)
- WCAG 2.1 SC 3.1.1 (Language of Page) and SC 3.1.2 (Language of Parts)
- EU Single Digital Gateway (SDG) Regulation (EU) 2018/1724 -- publications may need to be available in multiple EU languages

## Specificity Assessment
Depends on OpenRegister's register-i18n being implemented first. App-level work is primarily frontend (language selector, fallback display) and API layer (Accept-Language routing). OpenCatalogi has the largest translation surface of all apps -- publications with title, summary, description, and content fields are the core use case. The public-facing nature of catalogs makes language support particularly important for SDG compliance and cross-border accessibility.
