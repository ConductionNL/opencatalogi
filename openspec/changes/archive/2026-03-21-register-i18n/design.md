# Design: register-i18n

## Context

Enables multi-language support for OpenCatalogi register objects. Publications are the primary use case for multi-language content, as catalogs often serve both Dutch-speaking and international audiences under EU SDG requirements. Built on OpenRegister's register-i18n foundation.

## Goals / Non-Goals

**Goals:**
- Publication fields support multi-language content
- Catalog fields support multi-language content
- Page and theme fields support multi-language content
- Configurable language fallback chain
- Frontend language switching

**Non-Goals:**
- Automatic machine translation
- Per-user language preferences for admin content

## Decisions

1. Uses OpenRegister's register-level i18n infrastructure (translatable field annotations)
2. Fallback chain: requested language -> default language -> first available language
3. Frontend uses Accept-Language header and explicit lang query parameter

## File Changes

- Schema definitions — mark translatable fields
- Publication/Catalog API endpoints — accept and return language-specific content
- Frontend — language switcher component
