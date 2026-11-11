/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `MigrationObject.vue`'s performMigration: the
 * mapping refresh, the migration request, and the result and step it
 * leaves on success and on failure. The method runs against a plain `this`,
 * so no component is mounted.
 */

import axios from '@nextcloud/axios'
import MigrationObject from './MigrationObject.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))

/**
 * A plain `this` ready to migrate.
 *
 * @param {object} state - Overrides
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = {
		canMigrate: true,
		loading: false,
		step: 3,
		sourceRegister: { id: 1 },
		sourceSchema: { id: 2 },
		targetRegister: { id: 3 },
		targetSchema: { id: 4 },
		selectedObjects: [{ id: 'a' }, { id: 'b' }],
		mapping: { old: 'stale' },
		...state,
	}
	for (const [name, fn] of Object.entries(MigrationObject.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.convertUIToMapping = jest.fn(() => {
		ctx.mapping = { title: 'name' }
	})
	return ctx
}

beforeEach(() => {
	axios.post.mockReset()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('MigrationObject performMigration', () => {
	it('refreshes the mapping, posts the migration and shows the report', async () => {
		axios.post.mockResolvedValue({ data: { success: true } })
		const ctx = vm()
		await ctx.performMigration()
		expect(ctx.convertUIToMapping).toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith('/nc/apps/openregister/api/migrate', {
			sourceRegister: 1,
			sourceSchema: 2,
			targetRegister: 3,
			targetSchema: 4,
			objects: ['a', 'b'],
			mapping: { title: 'name' },
		})
		expect(ctx.migrationResult).toEqual({ success: true })
		expect(ctx.step).toBe(4)
		expect(ctx.loading).toBe(false)
	})

	it('reports a failed migration', async () => {
		axios.post.mockRejectedValue(new Error('refused'))
		const ctx = vm()
		await ctx.performMigration()
		expect(ctx.migrationResult).toEqual({ success: false, errors: ['refused'] })
		expect(ctx.step).toBe(4)
		expect(console.error).toHaveBeenCalledWith('Error performing migration:', expect.any(Error))
		axios.post.mockRejectedValue({})
		await ctx.performMigration()
		expect(ctx.migrationResult).toEqual({ success: false, errors: ['An error occurred during migration'] })
	})

	it('does nothing while it cannot migrate', async () => {
		const ctx = vm({ canMigrate: false })
		await ctx.performMigration()
		expect(ctx.convertUIToMapping).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
	})
})
