/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the federation listing modals' requests:
 * FederationAddDirectoryModal.submit, FederationEditListingModal.submit and
 * FederationDeleteListingModal.handleDelete — the request each sends, the
 * error each derives from a refused answer, and what each does afterwards.
 * Each method runs against a plain `this`, so no component is mounted.
 */

import FederationAddDirectoryModal from './FederationAddDirectoryModal.vue'
import FederationDeleteListingModal from './FederationDeleteListingModal.vue'
import FederationEditListingModal from './FederationEditListingModal.vue'

jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: null, setModal: jest.fn() },
}))

/**
 * A plain `this` for a component with the given state.
 *
 * @param {object} component - The component options
 * @param {object} state - The state
 * @return {object} The context
 */
function vm(component, state) {
	const ctx = { $emit: jest.fn(), ...state }
	for (const [name, fn] of Object.entries(component.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * A fetch that records its call and answers with the given status and body.
 *
 * @param {number} status - The HTTP status
 * @param {*} body - The JSON body, or an Error to fail parsing
 * @return {Function} The fetch
 */
function answer(status, body) {
	return jest.fn(async () => ({
		ok: status < 400,
		status,
		json: async () => {
			if (body instanceof Error) throw body
			return body
		},
	}))
}

afterEach(() => {
	jest.useRealTimers()
	delete global.fetch
})

const REFUSALS = [
	[{ data: { error: 'd' }, error: 'e', message: 'm' }, 'd'],
	[{ error: 'e', message: 'm' }, 'e'],
	[{ message: 'm' }, 'm'],
	[{}, 'HTTP 409'],
	[new Error('not json'), 'HTTP 409'],
]

describe('FederationAddDirectoryModal submit', () => {
	it('posts the trimmed URL and keeps the answer', async () => {
		global.fetch = answer(200, { added: 1 })
		const ctx = vm(FederationAddDirectoryModal, { url: '  https://peer.example/  ', submitting: false, error: 'old', result: null })
		await ctx.submit()
		expect(global.fetch).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/listings/add', {
			method: 'POST',
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ url: 'https://peer.example/' }),
		})
		expect(ctx.result).toEqual({ added: 1 })
		expect(ctx.error).toBe(null)
		expect(ctx.submitting).toBe(false)
	})

	it('does nothing for an empty URL', async () => {
		global.fetch = jest.fn()
		const ctx = vm(FederationAddDirectoryModal, { url: '   ' })
		await ctx.submit()
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it.each([
		[{ message: 'm', error: 'e' }, 'm'],
		[{ error: 'e' }, 'HTTP 409'],
		[new Error('x'), 'HTTP 409'],
	])('shows the refusal %j as %s', async (body, message) => {
		global.fetch = answer(409, body)
		const ctx = vm(FederationAddDirectoryModal, { url: 'u', result: 'kept' })
		await ctx.submit()
		expect(ctx.error).toBe(message)
		expect(ctx.result).toBe('kept')
		expect(ctx.submitting).toBe(false)
	})

	it('shows a network failure', async () => {
		global.fetch = jest.fn(async () => {
			throw new Error('offline')
		})
		const ctx = vm(FederationAddDirectoryModal, { url: 'u' })
		await ctx.submit()
		expect(ctx.error).toBe('offline')
	})
})

describe('FederationEditListingModal submit', () => {
	it('puts the integration level, emits the answer and closes', async () => {
		const { navigationStore } = jest.requireMock('../../store/store.js')
		navigationStore.setModal.mockClear()
		global.fetch = answer(200, { id: 'l1' })
		const ctx = vm(FederationEditListingModal, { listing: { id: 'l1' }, integrationLevel: { value: 'sync' } })
		await ctx.submit()
		expect(global.fetch).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/listings/l1', {
			method: 'PUT',
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ integrationLevel: 'sync' }),
		})
		expect(ctx.$emit).toHaveBeenCalledWith('saved', { id: 'l1' })
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(ctx.submitting).toBe(false)
	})

	it('sends no level without a selection and emits an unreadable answer as empty', async () => {
		global.fetch = answer(200, new Error('x'))
		const ctx = vm(FederationEditListingModal, { listing: { id: 'l1' }, integrationLevel: null })
		await ctx.submit()
		expect(global.fetch.mock.calls[0][1].body).toBe('{}')
		expect(ctx.$emit).toHaveBeenCalledWith('saved', {})
	})

	it('does nothing without a listing', async () => {
		global.fetch = jest.fn()
		await vm(FederationEditListingModal, { listing: null }).submit()
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it.each(REFUSALS)('shows the refusal %j as %s', async (body, message) => {
		global.fetch = answer(409, body)
		const ctx = vm(FederationEditListingModal, { listing: { id: 'l1' }, integrationLevel: { value: 'none' } })
		await ctx.submit()
		expect(ctx.error).toBe(message)
		expect(ctx.$emit).not.toHaveBeenCalled()
		expect(ctx.submitting).toBe(false)
	})
})

describe('FederationDeleteListingModal handleDelete', () => {
	it('deletes the listing, emits it and closes after a moment', async () => {
		jest.useFakeTimers()
		const { navigationStore } = jest.requireMock('../../store/store.js')
		navigationStore.setModal.mockClear()
		global.fetch = answer(200, new Error('no body needed'))
		const listing = { id: 'l2' }
		const ctx = vm(FederationDeleteListingModal, { listing, closeTimer: null, loading: false })
		await ctx.handleDelete()
		expect(global.fetch).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/listings/l2', {
			method: 'DELETE',
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(ctx.success).toBe(true)
		expect(ctx.$emit).toHaveBeenCalledWith('deleted', listing)
		expect(ctx.loading).toBe(false)
		expect(ctx.closeTimer).not.toBe(null)
		jest.advanceTimersByTime(799)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(ctx.closeTimer).toBe(null)
	})

	it('does nothing without a listing', async () => {
		global.fetch = jest.fn()
		await vm(FederationDeleteListingModal, { listing: null }).handleDelete()
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it.each(REFUSALS)('shows the refusal %j as %s', async (body, message) => {
		global.fetch = answer(409, body)
		const ctx = vm(FederationDeleteListingModal, { listing: { id: 'l2' }, closeTimer: null })
		await ctx.handleDelete()
		expect(ctx.success).toBe(false)
		expect(ctx.error).toBe(message)
		expect(ctx.$emit).not.toHaveBeenCalled()
		expect(ctx.loading).toBe(false)
	})
})
