/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `uploadSteps.js`, the steps the upload modal runs around a
 * file upload: reading the created attachment id, counting settled uploads,
 * the publication's files URL, the change notification, and reading a
 * schema's configuration embedded or from OpenRegister.
 */

import { dispatchObjectsChanged } from '@conduction/nextcloud-vue'
import {
	countSettled,
	embeddedSchemaConfiguration,
	fetchSchemaConfiguration,
	notifyPublicationChanged,
	publicationFilesUrl,
	recordCreatedId,
} from './uploadSteps.js'

jest.mock('@conduction/nextcloud-vue', () => ({ dispatchObjectsChanged: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, key) => String(params[key])),
}))

afterEach(() => {
	delete global.fetch
	dispatchObjectsChanged.mockReset()
})

describe('recordCreatedId', () => {
	it('stores the created attachment id on the file and in the id map', () => {
		const file = { name: 'besluit.pdf' }
		const idMap = {}

		recordCreatedId(file, { response: { data: [{ id: 42 }, { id: 43 }] }, idMap })

		expect(file.id).toBe(42)
		expect(idMap).toEqual({ 'besluit.pdf': 42 })
	})

	it('leaves the file alone when the answer holds no created id', () => {
		for (const response of [undefined, { data: null }, { data: {} }, { data: [] }, { data: [{}] }]) {
			const file = { name: 'x.pdf' }
			const idMap = {}
			recordCreatedId(file, { response, idMap })
			expect(file).toEqual({ name: 'x.pdf' })
			expect(idMap).toEqual({})
		}
	})

	it('swallows an answer that cannot be read', () => {
		const response = { get data() { throw new Error('unreadable') } }
		const file = { name: 'x.pdf' }
		expect(() => recordCreatedId(file, { response, idMap: {} })).not.toThrow()
		expect(file.id).toBeUndefined()
	})
})

describe('countSettled', () => {
	it('counts the settled results with the given status', () => {
		const results = [{ status: 'fulfilled' }, { status: 'rejected' }, { status: 'fulfilled' }]
		expect(countSettled(results, 'fulfilled')).toBe(2)
		expect(countSettled(results, 'rejected')).toBe(1)
		expect(countSettled([], 'fulfilled')).toBe(0)
	})
})

describe('publication files and change notice', () => {
	it('builds the OpenRegister files URL of the publication', () => {
		expect(publicationFilesUrl({ registerId: 5, schemaId: 9 }, { id: 'pub-1' }))
			.toBe('/apps/openregister/api/objects/5/9/pub-1/files')
	})

	it('announces the publication as changed with its register, schema and id', () => {
		notifyPublicationChanged({ registerId: 5, schemaId: 9 }, { id: 'pub-1' })
		expect(dispatchObjectsChanged).toHaveBeenCalledWith({ register: 5, schema: 9, id: 'pub-1' })
	})
})

describe('schema configuration', () => {
	it('reads an embedded schema\'s configuration, and none from an id or nothing', () => {
		expect(embeddedSchemaConfiguration({ id: 9, configuration: { allowFiles: true } })).toEqual({ allowFiles: true })
		expect(embeddedSchemaConfiguration({ id: 9 })).toBeUndefined()
		expect(embeddedSchemaConfiguration(9)).toBeNull()
		expect(embeddedSchemaConfiguration(null)).toBeNull()
	})

	it('fetches the configuration by id, uuid or slug', async () => {
		global.fetch = jest.fn(async () => ({ ok: true, json: async () => ({ configuration: { maxFiles: 3 } }) }))

		await expect(fetchSchemaConfiguration(9)).resolves.toEqual({ maxFiles: 3 })
		await fetchSchemaConfiguration({ uuid: 'u-9' })
		await fetchSchemaConfiguration({ slug: 'publication' })
		await fetchSchemaConfiguration({ id: 4, uuid: 'u-4' })

		expect(global.fetch.mock.calls.map(([url]) => url)).toEqual([
			'/index.php/apps/openregister/api/schemas/9',
			'/index.php/apps/openregister/api/schemas/u-9',
			'/index.php/apps/openregister/api/schemas/publication',
			'/index.php/apps/openregister/api/schemas/4',
		])
	})

	it('answers null without an id, on a failed request, an unreadable body or no configuration', async () => {
		global.fetch = jest.fn()
		await expect(fetchSchemaConfiguration({})).resolves.toBeNull()
		await expect(fetchSchemaConfiguration('')).resolves.toBeNull()
		expect(global.fetch).not.toHaveBeenCalled()

		global.fetch = jest.fn(async () => ({ ok: false }))
		await expect(fetchSchemaConfiguration(9)).resolves.toBeNull()

		global.fetch = jest.fn(async () => ({ ok: true, json: async () => { throw new SyntaxError('bad json') } }))
		await expect(fetchSchemaConfiguration(9)).resolves.toBeNull()

		global.fetch = jest.fn(async () => ({ ok: true, json: async () => ({ title: 'Publication' }) }))
		await expect(fetchSchemaConfiguration(9)).resolves.toBeNull()
	})
})
