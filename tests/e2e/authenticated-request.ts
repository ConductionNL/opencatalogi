/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A signed-in request context that can actually write.
 *
 * 🔴 WHY THIS FILE EXISTS. Playwright's built-in `request` fixture carries the
 * cookie jar that globalSetup saved, and nothing else. Nextcloud refuses a
 * cookie-session write that arrives without a `requesttoken` header, with
 * 412 Precondition Failed and `{"message":"CSRF check failed"}`, BEFORE the
 * request is dispatched. The controller never runs.
 *
 * Measured on 2026-09-19 against a live instance, same body, same URL:
 *
 *     cookie session, no requesttoken:
 *       POST /apps/opencatalogi/api/notices -> 412 CSRF check failed
 *     basic auth, same body:
 *       POST /apps/opencatalogi/api/notices -> 400 {"error":"missing-notice"}
 *
 * Sixteen tests in this suite were reading that 412 as the product's answer.
 * Fourteen of them failed for it, which at least showed up. The other kind is
 * worse: three refusal assertions listed 412 among the acceptable statuses, so
 * they passed on a CSRF rejection and would have passed on an instance where
 * the endpoint had no authorization at all.
 *
 * 🔑 So the token is not optional plumbing, and this fixture never degrades to
 * a tokenless context. If it cannot read a token it throws, because a harness
 * that quietly loses its credentials reports the same green as one that has
 * them.
 *
 * Anonymous probes must NOT use this. They build their own context with no
 * storage state, so that "an anonymous reader cannot" fails for the right
 * reason.
 */

import type { APIRequestContext } from '@playwright/test'

import { test as base, request } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '.auth', 'admin.json')

/** Nextcloud puts the session CSRF token on the `<head>` element. */
const TOKEN_PATTERN = /data-requesttoken="([^"]+)"/

/**
 * Read the CSRF token that belongs to the saved session.
 *
 * @param baseURL The instance under test.
 *
 * @return The token value.
 */
export async function sessionRequestToken(baseURL: string): Promise<string> {
	const probe = await request.newContext({ baseURL, storageState: STORAGE_STATE })
	try {
		const page = await probe.get('/index.php/apps/dashboard/')
		const html = await page.text()
		const match = TOKEN_PATTERN.exec(html)

		if (match === null) {
			throw new Error(
				'[authenticated-request] no data-requesttoken on /apps/dashboard/ '
					+ `(status ${page.status()}). The saved session in tests/e2e/.auth/admin.json `
					+ 'is not signed in, so every write would be refused with 412 and read as the '
					+ "product's answer. Check globalSetup rather than the app under test.",
			)
		}

		return match[1]
	} finally {
		await probe.dispose()
	}
}

/**
 * Build a signed-in context whose writes reach the controller.
 *
 * @param baseURL The instance under test.
 *
 * @return The context. The caller disposes it.
 */
export async function authenticatedContext(
	baseURL: string,
): Promise<APIRequestContext> {
	const requesttoken = await sessionRequestToken(baseURL)

	return await request.newContext({
		baseURL,
		storageState: STORAGE_STATE,
		extraHTTPHeaders: { requesttoken },
	})
}

/**
 * `test` with the built-in `request` fixture replaced by the signed-in one.
 *
 * A spec that writes imports `test` from here instead of `@playwright/test`,
 * and every `request: admin` in it keeps working, now with a token.
 */
export const test = base.extend<Record<string, never>>({
	request: async ({ baseURL }, use) => {
		const context = await authenticatedContext(baseURL as string)
		await use(context)
		await context.dispose()
	},
})

export { expect } from '@playwright/test'
