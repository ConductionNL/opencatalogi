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
 * worse: eight refusal assertions listed 412 among the acceptable statuses, so
 * they passed on a CSRF rejection and would have passed on an instance where
 * the endpoint had no authorization at all.
 *
 * 🔑 So the token is not optional plumbing, and this fixture never degrades to
 * a tokenless context. It asserts the saved session is signed in before it
 * hands one out, because `/index.php/csrftoken` answers 200 with a token to
 * ANYONE, signed in or not: a token alone proves nothing, and a harness that
 * quietly lost its session would otherwise report the same green as one that
 * kept it.
 *
 * Anonymous probes must NOT use this. They build their own context with no
 * storage state, so that "an anonymous reader cannot" fails for the right
 * reason.
 */

import type { APIRequestContext } from '@playwright/test'

import { test as base, request } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '.auth', 'admin.json')

/** Core's own token endpoint. Scraping `data-requesttoken` out of a page needs
 * a page that exists: `/apps/dashboard/` is 404 on an instance where the
 * dashboard app is off, and the failure would read as a login problem. */
const TOKEN_URL = '/index.php/csrftoken'

/** Answers 401 unless the caller is signed in, so it separates "no session"
 * from "no token". */
const WHOAMI_URL = '/ocs/v2.php/cloud/user?format=json'

/** One session, one token. Resolved once per worker, per base URL. */
const tokenCache = new Map<string, Promise<string>>()

/**
 * Read the CSRF token that belongs to the saved session.
 *
 * @param baseURL The instance under test.
 *
 * @return The token value.
 */
async function readSessionToken(baseURL: string): Promise<string> {
	const probe = await request.newContext({ baseURL, storageState: STORAGE_STATE })
	try {
		const whoami = await probe.get(WHOAMI_URL, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		if (whoami.ok() === false) {
			throw new Error(
				`[authenticated-request] ${WHOAMI_URL} answered ${whoami.status()}, so the `
					+ 'session saved in tests/e2e/.auth/admin.json is not signed in. Every write '
					+ "would then be refused with 412 and read as the product's answer. Check "
					+ 'globalSetup, not the app under test.',
			)
		}

		const response = await probe.get(TOKEN_URL)
		const token = (await response.json())?.token

		if (typeof token !== 'string' || token.length === 0) {
			throw new Error(
				`[authenticated-request] ${TOKEN_URL} answered ${response.status()} with no `
					+ 'token field. Without one every write is refused with 412 before the '
					+ 'controller runs.',
			)
		}

		return token
	} finally {
		await probe.dispose()
	}
}

/**
 * The session's CSRF token, computed once.
 *
 * @param baseURL The instance under test.
 *
 * @return The token value.
 */
export async function sessionRequestToken(baseURL: string): Promise<string> {
	let pending = tokenCache.get(baseURL)
	if (pending === undefined) {
		pending = readSessionToken(baseURL)
		tokenCache.set(baseURL, pending)
	}

	return await pending
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
