/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for what `DirectorySideBar.vue` does with a listing's publication
 * types: which switches start on, copying a remote type as a "KOPIE", deleting
 * a local copy by its source, and synchronising the listing or one type.
 */

import DirectorySideBar from './DirectorySideBar.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-request-token' }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		getCollection: jest.fn(),
		getActiveObject: jest.fn(),
		fetchCollection: jest.fn(),
		createObject: jest.fn(),
		deleteObject: jest.fn(),
	},
}))

const localTypes = [
	{ id: 'local-1', source: 'https://peer/types/1' },
	{ id: 'local-2', source: 'https://peer/types/2' },
]

/**
 * Settle every pending promise callback.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 8; i++) {
		await Promise.resolve()
	}
}

/**
 * A component context in its initial state with every method bound to it.
 *
 * @param {object} state Fields to override.
 * @return {object} The context.
 */
function vm(state = {}) {
	const ctx = { ...DirectorySideBar.data(), ...state }
	for (const [name, fn] of Object.entries(DirectorySideBar.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.getCollection.mockReturnValue({ results: localTypes })
	objectStore.getActiveObject.mockReturnValue({ id: 'listing-7', publicationType: [] })
	objectStore.createObject.mockResolvedValue({})
	objectStore.deleteObject.mockResolvedValue({})
	global.fetch = jest.fn()
})

afterEach(() => {
	delete global.fetch
})

describe('DirectorySideBar switches on load', () => {
	it('fetches the local publication types when created', () => {
		const ctx = vm()

		DirectorySideBar.created.call(ctx)

		expect(objectStore.fetchCollection).toHaveBeenCalledWith('publication_type')
	})

	it('turns on the switch of every listed type that has a local copy', () => {
		objectStore.getActiveObject.mockReturnValue({
			publicationType: ['https://peer/types/1', 'https://peer/types/9'],
		})
		const ctx = vm({ loading: true })

		ctx.checkPublicationTypeSwitches()

		expect(ctx.checkedPublicationTypeObject).toEqual({
			'https://peer/types/1': true,
			'https://peer/types/9': false,
		})
		expect(ctx.loading).toBe(false)
	})

	it('sets no switch for a listing without publication types', () => {
		objectStore.getActiveObject.mockReturnValue({ publicationType: 'not a list' })
		const ctx = vm({ loading: true })

		ctx.checkPublicationTypeSwitches()

		expect(ctx.checkedPublicationTypeObject).toEqual({})
		expect(ctx.loading).toBe(false)
	})

	it('rechecks the switches when another listing becomes active', () => {
		const ctx = vm({ checkPublicationTypeSwitches: jest.fn() })

		DirectorySideBar.watch.listingItem.handler.call(ctx, { id: 'listing-8' })

		expect(ctx.loading).toBe(true)
		expect(ctx.switchedListing).toBe(true)
		expect(ctx.checkPublicationTypeSwitches).toHaveBeenCalledTimes(1)
	})

	it('leaves the switches alone without an active listing or without local types', () => {
		const ctx = vm({ checkPublicationTypeSwitches: jest.fn() })

		DirectorySideBar.watch.listingItem.handler.call(ctx, false)
		objectStore.getCollection.mockReturnValue({})
		DirectorySideBar.watch.listingItem.handler.call(ctx, { id: 'listing-8' })

		expect(ctx.checkPublicationTypeSwitches).not.toHaveBeenCalled()
		expect(ctx.switchedListing).toBe(false)
	})

	it('reads the active listing and copies the switch state', () => {
		const listing = { id: 'listing-7' }
		objectStore.getActiveObject.mockReturnValue(listing)

		expect(DirectorySideBar.computed.listingItem.call(vm())).toBe(listing)
		const switches = { a: true }
		const copy = DirectorySideBar.computed.checkedPublicationType.call(vm({ checkedPublicationTypeObject: switches }))
		expect(copy).toEqual({ a: true })
		expect(copy).not.toBe(switches)
	})
})

describe('DirectorySideBar getPublicationTypeId', () => {
	it('finds the local copy by its source', () => {
		expect(vm().getPublicationTypeId('https://peer/types/2')).toBe('local-2')
	})

	it('finds nothing for an unknown source', () => {
		expect(vm().getPublicationTypeId('https://peer/types/9')).toBeUndefined()
	})
})

describe('DirectorySideBar copyPublicationType', () => {
	it('creates a copy of a remote type that has no local copy yet', async () => {
		global.fetch.mockResolvedValue({ json: async () => ({ source: 'https://peer/types/9', title: 'Besluit' }) })
		const ctx = vm({ createPublicationType: jest.fn() })

		ctx.copyPublicationType('https://peer/types/9')
		expect(ctx.loading).toBe(true)
		await flush()

		expect(global.fetch).toHaveBeenCalledWith('https://peer/types/9', { method: 'GET' })
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('publication_type')
		expect(ctx.createPublicationType).toHaveBeenCalledWith({ source: 'https://peer/types/9', title: 'Besluit' })
		expect(ctx.loading).toBe(false)
	})

	it('does not copy a type that already has a local copy', async () => {
		global.fetch.mockResolvedValue({ json: async () => ({ source: 'https://peer/types/1', title: 'Besluit' }) })
		const ctx = vm({ createPublicationType: jest.fn() })

		ctx.copyPublicationType('https://peer/types/1')
		await flush()

		expect(ctx.createPublicationType).not.toHaveBeenCalled()
	})

	it('keeps the error when the remote type cannot be fetched', async () => {
		global.fetch.mockRejectedValue('offline')
		const ctx = vm({ createPublicationType: jest.fn() })

		ctx.copyPublicationType('https://peer/types/9')
		await flush()

		expect(ctx.error).toBe('offline')
		expect(ctx.loading).toBe(false)
		expect(ctx.createPublicationType).not.toHaveBeenCalled()
	})
})

describe('DirectorySideBar createPublicationType', () => {
	it('stores the copy as KOPIE without the remote ids or empty properties', async () => {
		const ctx = vm()

		ctx.createPublicationType({ id: 'r-1', _id: 'm-1', title: 'Besluit', properties: {}, source: 'https://peer/types/9' })
		expect(ctx.loading).toBe(true)
		await flush()

		expect(objectStore.createObject).toHaveBeenCalledWith('publication_type', { title: 'KOPIE: Besluit', source: 'https://peer/types/9' })
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('publication_type')
		expect(ctx.loading).toBe(false)
	})

	it('keeps properties that are set', () => {
		vm().createPublicationType({ title: 'Besluit', properties: { kenmerk: { type: 'string' } } })

		expect(objectStore.createObject).toHaveBeenCalledWith('publication_type', {
			title: 'KOPIE: Besluit',
			properties: { kenmerk: { type: 'string' } },
		})
	})

	it('keeps the error when the store refuses the copy', async () => {
		objectStore.createObject.mockRejectedValue('refused')
		const ctx = vm()

		ctx.createPublicationType({ title: 'Besluit', properties: {} })
		await flush()

		expect(ctx.error).toBe('refused')
		expect(ctx.loading).toBe(false)
		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
	})
})

describe('DirectorySideBar deletePublicationType', () => {
	it('deletes the local copy of the source and refreshes', async () => {
		const ctx = vm()

		ctx.deletePublicationType('https://peer/types/2')
		expect(ctx.loading).toBe(true)
		await flush()

		expect(objectStore.deleteObject).toHaveBeenCalledWith('publication_type', 'local-2')
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('publication_type')
		expect(ctx.loading).toBe(false)
	})

	it('keeps the error when the delete fails', async () => {
		objectStore.deleteObject.mockRejectedValue('refused')
		const ctx = vm()

		ctx.deletePublicationType('https://peer/types/2')
		await flush()

		expect(ctx.error).toBe('refused')
		expect(ctx.loading).toBe(false)
	})

	it('copies on a switch turned on and deletes on one turned off', () => {
		const ctx = vm({ copyPublicationType: jest.fn(), deletePublicationType: jest.fn() })

		ctx.applyPublicationTypeSwitch('https://peer/types/1', true)
		ctx.applyPublicationTypeSwitch('https://peer/types/2', false)

		expect(ctx.copyPublicationType).toHaveBeenCalledWith('https://peer/types/1')
		expect(ctx.deletePublicationType).toHaveBeenCalledWith('https://peer/types/2')
	})
})

describe('DirectorySideBar synchronisation', () => {
	it('synchronises the active listing and refreshes the listings', async () => {
		global.fetch.mockResolvedValue({})
		const ctx = vm()

		ctx.synDirectroy()
		expect(ctx.syncLoading).toBe(true)
		await flush()

		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/listings/synchronise/listing-7', { method: 'GET' })
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('listing')
		expect(ctx.syncLoading).toBe(false)
	})

	it('keeps the error of a failed listing synchronisation', async () => {
		global.fetch.mockRejectedValue('offline')
		const ctx = vm()

		ctx.synDirectroy()
		await flush()

		expect(ctx.error).toBe('offline')
		expect(ctx.syncLoading).toBe(false)
		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
	})

	it('flips a type listed and sends its new state with the request token', async () => {
		global.fetch.mockResolvedValue({})
		const ctx = vm()
		const type = { listed: false, source: 'https://peer/types/1' }

		ctx.togglePublicationType(type)
		expect(ctx.publicationTypeLoading).toBe(true)
		await flush()

		expect(type.listed).toBe(true)
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/publication_types/synchronise', {
			method: 'POST',
			headers: { requesttoken: 'test-request-token', 'Content-Type': 'application/json' },
			body: JSON.stringify({ listed: true, source: 'https://peer/types/1' }),
		})
		expect(ctx.publicationTypeLoading).toBe(false)
	})

	it('keeps the error of a failed type synchronisation', async () => {
		global.fetch.mockRejectedValue('offline')
		const ctx = vm()

		ctx.synchronizePublicationType({ listed: true, source: 'https://peer/types/1' })
		await flush()

		expect(ctx.error).toBe('offline')
		expect(ctx.publicationTypeLoading).toBe(false)
	})
})

describe('DirectorySideBar openLink', () => {
	it('opens the link in the target asked for', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)

		vm().openLink('https://peer/catalog', '_blank')
		vm().openLink('https://peer/other')

		expect(open.mock.calls).toEqual([['https://peer/catalog', '_blank'], ['https://peer/other', '']])
		open.mockRestore()
	})
})
