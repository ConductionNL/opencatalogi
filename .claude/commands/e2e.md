---
description: Run the Playwright end-to-end tests
---

Run the Playwright end-to-end tests in `tests/e2e/`.

1. Install the browser once: `npm run test:e2e:install`.
2. Make sure a Nextcloud with OpenRegister and OpenCatalogi enabled is running (for example `docker compose -f docker/docker-compose.yml up -d --build`, see README.md "Try it with Docker").
3. Run `npm run test:e2e` (config: `playwright.config.ts`). To run one spec, pass it after `--`, for example `npm run test:e2e -- tests/e2e/<file>.spec.ts`.

Report failing specs with their error. Do not update visual baselines unless asked.
