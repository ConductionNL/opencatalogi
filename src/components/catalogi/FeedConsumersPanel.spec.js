/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Feed consumers section: its admin calls, the key shown once, and the
 * refusals it shows instead of failing silently.
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md
 */

import axios from '@nextcloud/axios'
import FeedConsumersPanel from './FeedConsumersPanel.vue'
import { refusalOf } from '../../services/feedConsumerRefusal.js'
import { consumersUrl, isActiveKey, listConsumers } from '../../services/feedConsumers.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k]),
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))

const BASE = '/index.php/apps/opencatalogi/api/catalogs/woo/consumers'

/**
 * A bare panel over the component's data and methods.
 *
 * @return {object} The panel.
 */
function panel() {
	const vm = { catalogSlug: 'woo', ...FeedConsumersPanel.data() }
	for (const [name, method] of Object.entries(FeedConsumersPanel.methods)) {
		vm[name] = typeof method === 'function' ? method.bind(vm) : method
	}
	return vm
}

const LISTING = {
	results: [{ id: 'c1', name: 'Studiekeuze', contact: 'dev@example.org', keys: [{ id: 'k1', createdAt: '2026-11-01T00:00:00Z' }] }],
	instanceAccounts: ['ooapi-reader'],
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.get.mockResolvedValue({ data: LISTING })
})

test('the listing reads the catalogue\'s consumers and the instance-wide accounts', async () => {
	const vm = panel()
	await vm.load()

	expect(axios.get).toHaveBeenCalledWith(BASE)
	expect(vm.consumers).toEqual(LISTING.results)
	expect(vm.instanceAccounts).toEqual(['ooapi-reader'])
	expect(await listConsumers('woo')).toEqual({ consumers: LISTING.results, instanceAccounts: ['ooapi-reader'] })
})

test('adding a consumer posts its name and contact, trimmed, and clears the form', async () => {
	axios.post.mockResolvedValue({ data: { id: 'c2' } })
	const vm = panel()
	vm.name = ' Kiesmbo '
	vm.contact = ' info@kiesmbo.example '

	await vm.add()

	expect(axios.post).toHaveBeenCalledWith(BASE, { name: 'Kiesmbo', contact: 'info@kiesmbo.example' })
	expect([vm.name, vm.contact]).toEqual(['', ''])
	expect(axios.get).toHaveBeenCalledTimes(1)
})

test('a generated key is shown once, revoking and disabling call their endpoints', async () => {
	axios.post.mockResolvedValue({ data: { key: 'ocfc_c1.k2.secret', consumer: {} } })
	const vm = panel()

	await vm.issue({ id: 'c1' })
	expect(axios.post).toHaveBeenLastCalledWith(`${BASE}/c1/keys`, {})
	expect(vm.newKey).toBe('ocfc_c1.k2.secret')

	await vm.revoke({ id: 'c1' }, { id: 'k1' })
	expect(axios.post).toHaveBeenLastCalledWith(`${BASE}/c1/keys/k1/revoke`, {})

	await vm.disable({ id: 'c/1' })
	expect(axios.post).toHaveBeenLastCalledWith(`${BASE}/c%2F1/disable`, {})
})

test('a refused call shows the server\'s reason and leaves busy off', async () => {
	axios.post.mockRejectedValue({ response: { data: { error: 'A consumer holds at most two active keys; revoke one first' } } })
	const vm = panel()

	await vm.issue({ id: 'c1' })

	expect(vm.error).toBe('A consumer holds at most two active keys; revoke one first')
	expect(vm.newKey).toBe('')
	expect(vm.busy).toBe(false)
	expect(refusalOf({ message: 'Network Error' })).toBe('t:Error: Network Error')
})

test('a key is active until it is revoked or expires', () => {
	const now = new Date('2026-11-14T12:00:00Z')
	expect(isActiveKey({ id: 'k' }, now)).toBe(true)
	expect(isActiveKey({ id: 'k', expiresAt: '2026-12-01T00:00:00Z' }, now)).toBe(true)
	expect(isActiveKey({ id: 'k', expiresAt: '2026-11-01T00:00:00Z' }, now)).toBe(false)
	expect(isActiveKey({ id: 'k', revokedAt: '2026-11-10T00:00:00Z' }, now)).toBe(false)
	expect(isActiveKey(null, now)).toBe(false)
	expect(consumersUrl('woo', '/x')).toBe(`${BASE}/x`)
})
