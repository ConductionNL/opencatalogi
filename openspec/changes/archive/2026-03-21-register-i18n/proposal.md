# Register Content Internationalization

## Problem
Enable multi-language support for OpenCatalogi's register objects, allowing users to view and manage publication and catalog content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`).
OpenCatalogi is the most translation-heavy app in the Conduction suite. Publications are the primary use case for multi-language content, as catalogs often serve both Dutch-speaking and international audiences, particularly under EU Single Digital Gateway (SDG) requirements.

## Proposed Solution
Implement Register Content Internationalization following the detailed specification. Key requirements include:
- Requirement: Publication fields MUST support multi-language content
- Requirement: Catalog fields MUST support multi-language content
- Requirement: Page and theme fields MUST support multi-language content
- Requirement: Language fallback chain MUST be configurable and predictable
- Requirement: Frontend MUST provide language switching

## Scope
This change covers all requirements defined in the register-i18n specification.

## Success Criteria
- Publication with Dutch and English titles
- Publication summary in requested language
- Publication content falls back to default language
- All publication text fields are translatable
- Publication search returns results in requested language
