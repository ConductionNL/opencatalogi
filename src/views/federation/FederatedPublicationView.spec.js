/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FederatedPublicationView: reads a remote publication and its attachments
 * through the federation endpoints, keeps the source's download URLs, and
 * links to the source's own history-mode page.
 *
 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md
 */

import axios from '@nextcloud/axios'
import FederatedPublicationView from './FederatedPublicationView.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k]),
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true }))

const REMOTE = {
	id: 'pub-1',
	title: 'Besluit parkeervergunningen',
	summary: 'Nieuwe regels',
	organization: { name: 'Gemeente Buurdorp' },
	published: '2026-10-01T00:00:00+00:00',
	'@self': { directory: 'https://buurdorp.example/index.php/apps/opencatalogi/api/directory', uri: 'https://buurdorp.example/apps/openregister/api/objects/pub-1' },
}

/**
 * A bare page over the component's data, computed and methods.
 *
 * @param {object} route The route: params and query.
 * @return {object} The page.
 */
function page(route) {
	const vm = { ...FederatedPublicationView.data(), $route: route }
	for (const [name, getter] of Object.entries(FederatedPublicationView.computed)) {
		Object.defineProperty(vm, name, { get: getter.bind(vm) })
	}
	for (const [name, method] of Object.entries(FederatedPublicationView.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

beforeEach(() => axios.get.mockReset())

test('the publication and its attachments are read through the federation endpoints', async () => {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/attachments')
			? { results: [{ id: 'a1', title: 'besluit.pdf', downloadUrl: 'https://buurdorp.example/index.php/core/f/1' }, { id: 'a2', name: 'bijlage.odt' }] }
			: REMOTE,
	}))
	const vm = page({ params: { id: 'pub-1' }, query: { catalog: 'besluiten' } })

	await vm.load()

	expect(axios.get.mock.calls.map((call) => call[0])).toEqual([
		'/index.php/apps/opencatalogi/api/federation/publications/pub-1',
		'/index.php/apps/opencatalogi/api/federation/publications/pub-1/attachments',
	])
	expect(vm.loading).toBe(false)
	expect(vm.title).toBe('Besluit parkeervergunningen')
	expect(vm.details.map((row) => row.label)).toEqual(['t:Organization', 't:Publication date', 't:Source'])
	expect(vm.details[0].value).toBe('Gemeente Buurdorp')
	expect(vm.details[2].value).toBe(REMOTE['@self'].directory)
	expect(vm.attachmentUrl(vm.attachments[0])).toBe('https://buurdorp.example/index.php/core/f/1')
	expect(vm.attachmentName(vm.attachments[1])).toBe('bijlage.odt')
	expect(vm.attachmentUrl(vm.attachments[1])).toBeNull()
})

test('Open at the source uses the source\'s history-mode page, or its app root without a known catalogue', async () => {
	axios.get.mockResolvedValue({ data: REMOTE })
	const withSlug = page({ params: { id: 'pub-1' }, query: { catalog: 'besluiten' } })
	await withSlug.load()
	expect(withSlug.sourceLink).toBe('https://buurdorp.example/index.php/apps/opencatalogi/publications/besluiten/pub-1')
	expect(withSlug.sourceLink).not.toContain('#')

	const withoutSlug = page({ params: { id: 'pub-1' }, query: {} })
	await withoutSlug.load()
	expect(withoutSlug.sourceLink).toBe('https://buurdorp.example/index.php/apps/opencatalogi/')
})

test('an unreachable source says so instead of showing an empty page', async () => {
	axios.get.mockRejectedValue(new Error('down'))
	const vm = page({ params: { id: 'pub-1' }, query: {} })

	await vm.load()

	expect(vm.loadFailed).toBe(true)
	expect(vm.loading).toBe(false)
	expect(vm.publication).toBeNull()
	expect(axios.get).toHaveBeenCalledTimes(1)
})

test('attachments that cannot be read are reported apart from the metadata', async () => {
	axios.get.mockImplementation((url) => (url.endsWith('/attachments') ? Promise.reject(new Error('404')) : Promise.resolve({ data: REMOTE })))
	const vm = page({ params: { id: 'pub-1' }, query: {} })

	await vm.load()

	expect(vm.loadFailed).toBe(false)
	expect(vm.attachmentsFailed).toBe(true)
	expect(vm.attachments).toEqual([])
	expect(vm.title).toBe('Besluit parkeervergunningen')
})
