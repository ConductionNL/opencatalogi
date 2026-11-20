---
description: Run the PHP and frontend quality checks for this app
---

Run this app's quality suite from the repository root and report each failure with file and line.

1. PHP: `composer check:strict` (runs `lint`, `phpcs`, `phpmd`, `psalm`, `phpstan` and `test:all`). It needs `composer install` first. Read its last lines: outside a Nextcloud server tree `test:all` is skipped, so a pass says nothing about the PHPUnit suite.
2. Frontend: `npm run lint`, then `npm run stylelint`, then `npm run format`. They need `npm install` first.
3. Manifest and translations: `npm run check:manifest`, `npm run check:l10n` and `npm run check:l10n-js`. For l10n failures follow CLAUDE.md; do not hand-edit `l10n/*.js`.

Fix only what your change introduced. If a check already failed on `development` before your change, say so instead of fixing it silently.
