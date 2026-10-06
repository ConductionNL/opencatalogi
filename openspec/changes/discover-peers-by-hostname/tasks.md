# Tasks

## 1. Resolver

- [x] 1.1 `DirectoryService::resolveDirectoryUrl()`: bare host → capabilities → `links.directory`, `/index.php` when pretty URLs are off, conventional fallback.
- [x] 1.2 `syncDirectory()` resolves before validation; full URLs and non-host strings behave as before.
- [x] 1.3 `safeGet()` takes optional headers (sent on every hop).
- [x] 1.4 OpenCatalogi `discovery` manifest block with `links.directory`.

## 2. Verification

- [x] 2.1 Unit tests: full URL untouched (no request), capability read (with header), `/index.php` prefix, fallback on missing and on failing capabilities, unsafe advertised path ignored, local host refused.
- [x] 2.2 Existing `DirectoryServiceTest` suite still green (invalid-URL message unchanged).
- [ ] 2.3 Live: add `nc-fed-2.local` (allowlisted) by hostname in the Add-directory dialog on a two-instance docker federation.

## 3. Follow-up

- [ ] 3.1 Mention hostname input in the Add-directory dialog's help text (new l10n string in every locale).
