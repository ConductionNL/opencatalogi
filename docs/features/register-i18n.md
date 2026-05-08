# Register Content Internationalization

## Overview

Multi-language support for OpenCatalogi register objects enables users to view and manage publication and catalog content in their preferred language. This is essential for EU Single Digital Gateway (SDG) compliance, where catalogs serve both Dutch-speaking and international audiences.

## Supported Content

The following entity fields support multi-language content:

- **Publications** - Title, summary, description, category labels
- **Catalogs** - Name, description
- **Pages** - Title, content
- **Themes** - Name, description

## Language Selection

Content language is determined by:

1. Explicit `lang` query parameter on API requests
2. `Accept-Language` HTTP header
3. Configured default language fallback

## Fallback Chain

When content is not available in the requested language:

1. Requested language (e.g., `en`)
2. Default language (configured per catalog, typically `nl`)
3. First available language

## Frontend

A language switcher component allows users to toggle between available languages when viewing publications with multiple translations.
