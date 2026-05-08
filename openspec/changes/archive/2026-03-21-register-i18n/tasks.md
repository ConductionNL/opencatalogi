# Tasks: register-i18n

## 1. Schema Configuration

- [x] 1.1 Mark publication text fields as translatable in schema definitions
- [x] 1.2 Mark catalog text fields as translatable
- [x] 1.3 Mark page and theme text fields as translatable

## 2. API Support

- [x] 2.1 Accept `lang` query parameter on publication endpoints
- [x] 2.2 Implement language fallback chain (requested -> default -> first available)
- [x] 2.3 Return content in requested language with metadata about available translations

## 3. Frontend

- [x] 3.1 Language switcher component for publication viewing
- [x] 3.2 Wire language switcher to API `lang` parameter

## 4. Unit Tests (ADR-009)

- [x] 4.1 Test language fallback chain returns correct content
- [x] 4.2 Test publication with multiple language versions

## 5. Documentation (ADR-010)

- [x] 5.1 Feature documentation at docs/features/register-i18n.md

## 6. Internationalization (ADR-005)

- [x] 6.1 UI labels for language switcher in nl/en
