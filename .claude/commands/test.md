---
description: Run the PHP and frontend unit tests
---

Run the unit tests from the repository root and summarise passes and failures.

1. Frontend (needs `npm install`):
   - `npm test` runs Jest on the specs under `src/` (`jest.config.js`).
   - `npm run test:unit` runs Vitest on `tests/vitest/` (`vitest.config.js`).
2. PHP (needs `composer install`): `composer test:unit` runs PHPUnit with `phpunit.xml`. The suite needs a Nextcloud server tree at `../../lib/base.php`; if it is missing, say that the PHP tests did not run rather than reporting them green.

If `$ARGUMENTS` names a file or test, run only that one (for example `npx vitest run <file>` or `npx jest <file>`).
