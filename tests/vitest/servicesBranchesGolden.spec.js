/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the branchy service helpers: fetchNextcloudGroups,
 * getNextcloudGroups, isUserLoggedIn, listingSyncStatus and loadInstanceBanners.
 * Each case records the exact answer, including falsy non-boolean values.
 * Run with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

const auth = vi.hoisted(() => ({ user: null, throws: false }))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => {
		if (auth.throws) {
			throw new Error('no session')
		}
		return auth.user
	},
}))

const {
	fetchNextcloudGroups,
	getNextcloudGroups,
	clearGroupsCache,
	isUserLoggedIn,
} = await import('../../src/services/nextcloudGroups.js')
const { listingSyncStatus } = await import('../../src/services/listingSyncStatus.js')
const { loadInstanceBanners } = await import('../../src/services/instanceBanners.js')

const FIXTURE = path.resolve(__dirname, 'fixtures/services-branches-golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded =
	RECORD || !fs.existsSync(FIXTURE)
		? {}
		: JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
const actual = {}

/**
 * A JSON-safe form of a value that keeps undefined and the value's type.
 *
 * @param {*} value The value
 * @return {object} The type and the value
 */
function shape(value) {
	return { type: typeof value, value: value === undefined ? '(undefined)' : value }
}

/**
 * Compare one case with the recording, or record it.
 *
 * @param {string} name The case name
 * @param {*} value The answer
 */
function check(name, value) {
	actual[name] = shape(value)
	if (!RECORD) {
		expect(actual[name]).toEqual(recorded[name])
	}
}

/**
 * A storage double that answers fixed keys.
 *
 * @param {object} values Key to value
 * @return {{getItem: Function}} The storage
 */
function storage(values) {
	return { getItem: (key) => (key in values ? values[key] : null) }
}

describe('service branches (golden)', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
		clearGroupsCache()
		auth.user = null
		auth.throws = false
		if (RECORD) {
			fs.writeFileSync(
				FIXTURE,
				JSON.stringify({ ...recorded, ...actual }, null, '\t') + '\n',
			)
		}
	})

	const groupAnswers = {
		'two groups': { data: { ocs: { data: { groups: ['admin', 'editors'] } } } },
		'empty group list': { data: { ocs: { data: { groups: [] } } } },
		'groups null': { data: { ocs: { data: { groups: null } } } },
		'no ocs data': { data: { ocs: { data: null } } },
		'no ocs': { data: { ocs: null } },
		'data zero': { data: 0 },
		'data empty string': { data: '' },
		'no data': {},
		throws: new Error('offline'),
	}

	it.each(Object.keys(groupAnswers))('fetchNextcloudGroups: %s', async (name) => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const answer = groupAnswers[name]
		vi.spyOn(axios, 'get').mockImplementation(async () => {
			if (answer instanceof Error) {
				throw answer
			}
			return answer
		})
		check('fetch: ' + name, await fetchNextcloudGroups())
	})

	it('getNextcloudGroups: cache, fallback and expiry', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const calls = []
		let groups = ['a']
		vi.spyOn(axios, 'get').mockImplementation(async () => {
			calls.push(groups)
			if (groups instanceof Error) {
				throw groups
			}
			return { data: { ocs: { data: { groups } } } }
		})
		const at = async (now) => {
			vi.spyOn(Date, 'now').mockReturnValue(now)
			return (await getNextcloudGroups()).map((group) => group.value)
		}
		const steps = []
		steps.push(await at(1000))
		groups = ['b']
		steps.push(await at(2000))
		steps.push(await at(1000 + 5 * 60 * 1000))
		groups = []
		steps.push(await at(1000 + 10 * 60 * 1000 + 1))
		steps.push(await at(1000 + 10 * 60 * 1000 + 2))
		clearGroupsCache()
		vi.spyOn(Date, 'now').mockReturnValue(0)
		steps.push((await getNextcloudGroups()).length)
		check('get: sequence', { steps, calls: calls.length })
	})

	const users = {
		'uid admin': { user: { uid: 'admin' } },
		'uid empty': { user: { uid: '' } },
		'uid missing': { user: {} },
		'no user, local nc_username': { user: null, local: { nc_username: 'x' } },
		'no user, local opencatalogi_user': {
			user: null,
			local: { opencatalogi_user: 'y' },
		},
		'no user, session nc_username': {
			user: null,
			session: { nc_username: 'z' },
		},
		'no user, local empty string': { user: null, local: { nc_username: '' } },
		'no user, nothing stored': { user: null },
		'session lookup throws': { user: null, throws: true },
	}

	it.each(Object.keys(users))('isUserLoggedIn: %s', (name) => {
		const c = users[name]
		auth.user = c.user
		auth.throws = c.throws === true
		vi.stubGlobal('localStorage', storage(c.local || {}))
		vi.stubGlobal('sessionStorage', storage(c.session || {}))
		check('logged in: ' + name, isUserLoggedIn())
	})

	const listings = {
		null: null,
		undefined,
		empty: {},
		'healthy, same timestamps': {
			lastSync: 't2',
			lastSuccessAt: 't2',
			lastError: 'old',
		},
		'failed attempt after success': {
			lastSync: 't3',
			lastSuccessAt: 't2',
			lastError: 'boom',
		},
		'failed attempt, error not a string': {
			lastSync: 't3',
			lastSuccessAt: 't2',
			lastError: 42,
		},
		'never succeeded': { lastSync: 't1', lastError: 'down' },
		'success only': { lastSuccessAt: 't2' },
		'empty strings': { lastSync: '', lastSuccessAt: '', lastError: '' },
	}

	it.each(Object.keys(listings))('listingSyncStatus: %s', (name) => {
		check('sync: ' + name, listingSyncStatus(listings[name]))
	})

	const bannerAnswers = {
		'read fails': new Error('500'),
		'no banners key': { data: {} },
		'banners not a list': { data: { banners: 'x' } },
		mixed: {
			data: {
				banners: [
					null,
					{ body: 'no id' },
					{ id: 0, body: 'zero id' },
					{ id: 'b1', body: 42 },
					{ id: 'b2', body: '   ' },
					{ id: 'b3', body: 'Hello' },
					{
						id: 7,
						body: 'Numbered',
						severity: 'warning',
						dismissable: false,
					},
					{ id: 'b4', body: 'Kept', severity: '', dismissable: 0 },
				],
			},
		},
	}

	it.each(Object.keys(bannerAnswers))('loadInstanceBanners: %s', async (name) => {
		const answer = bannerAnswers[name]
		const http = {
			get: async (url) => {
				if (answer instanceof Error) {
					throw answer
				}
				return { ...answer, url }
			},
		}
		check(
			'banners: ' + name,
			await loadInstanceBanners(http, (p) => '/apps/opencatalogi' + p),
		)
	})
})
