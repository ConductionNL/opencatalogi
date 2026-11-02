/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for getNextcloudGroups: the API groups, the fallback groups
 * when the API has none, the five-minute cache and when it is refetched.
 * Run with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	clearGroupsCache,
	getNextcloudGroups,
} from '../../src/services/nextcloudGroups.js'

const FIXTURE = path.resolve(
	__dirname,
	'fixtures/nextcloud-groups-cache-golden.json',
)
const RECORD = process.env.RECORD_GOLDEN === '1'

/**
 * Make the groups endpoint answer with the given groups, or fail.
 *
 * @param {Array<string>|Error} groups The groups, or the error to throw
 */
function answer(groups) {
	vi.spyOn(axios, 'get').mockImplementation(async () => {
		if (groups instanceof Error) {
			throw groups
		}
		return { data: { ocs: { data: { groups } } } }
	})
}

/**
 * Call getNextcloudGroups at a given time.
 *
 * @param {number} now The time in milliseconds
 * @return {Promise<Array>} The groups
 */
async function at(now) {
	vi.spyOn(Date, 'now').mockReturnValue(now)
	return getNextcloudGroups()
}

describe('getNextcloudGroups (golden)', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		clearGroupsCache()
	})

	it('matches the recorded sequence', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const steps = {}

		answer(['admin', 'editors'])
		const first = await at(1000)
		steps.apiGroups = first
		answer(['other'])
		const cached = await at(1000 + 5 * 60 * 1000 - 1)
		steps.cacheHit = { groups: cached, sameArray: cached === first }
		steps.cacheExpired = await at(1000 + 5 * 60 * 1000)

		clearGroupsCache()
		answer([])
		const fallback = await at(2000)
		steps.fallbackWhenEmpty = fallback
		answer(['late'])
		steps.fallbackIsCached = (await at(3000)) === fallback

		clearGroupsCache()
		answer(new Error('down'))
		const failed = await at(4000)
		steps.fallbackWhenFailing = failed
		steps.freshFallbackObjects = failed !== fallback && failed[0] !== fallback[0]
		steps.requests = axios.get.mock.calls

		const actual = JSON.parse(JSON.stringify(steps))
		if (RECORD) {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(fs.readFileSync(FIXTURE, 'utf8')))
	})
})
