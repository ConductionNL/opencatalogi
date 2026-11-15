/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the steps the upload dialog shares between a first upload and
 * a retry (src/modals/generic/uploadSteps.js): noting the id an upload
 * created, counting settled uploads, the publication's files URL and change
 * notice, and finding a schema's configuration.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	countSettled,
	embeddedSchemaConfiguration,
	fetchSchemaConfiguration,
	notifyPublicationChanged,
	publicationFilesUrl,
	recordCreatedId,
} from '../../src/modals/generic/uploadSteps.js'

const library = vi.hoisted(() => ({ dispatchObjectsChanged: vi.fn() }))

vi.mock('@conduction/nextcloud-vue', () => library)
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (whole, key) => String(params[key])),
}))

describe('recordCreatedId', () => {
	it('notes the first created id on the file and in the id map', () => {
		const file = { name: 'besluit.pdf' }
		const idMap = { 'other.pdf': 'a-0' }

		recordCreatedId(file, { response: { data: [{ id: 'a-1' }, { id: 'a-2' }] }, idMap })

		expect(file.id).toBe('a-1')
		expect(idMap).toEqual({ 'other.pdf': 'a-0', 'besluit.pdf': 'a-1' })
	})

	it.each([
		['no answer', undefined],
		['no data', {}],
		['data that is not a list', { data: { id: 'a-1' } }],
		['an empty list', { data: [] }],
		['a first entry without an id', { data: [{ name: 'x' }] }],
		['a first entry that is null', { data: [null] }],
	])('changes nothing for %s', (label, response) => {
		const file = { name: 'besluit.pdf', id: 'kept' }
		const idMap = {}

		recordCreatedId(file, { response, idMap })

		expect(file.id).toBe('kept')
		expect(idMap).toEqual({})
	})

	it('swallows an answer whose entries cannot be read', () => {
		const file = { name: 'besluit.pdf' }
		const response = { data: [{ get id() { throw new Error('unreadable') } }] }

		expect(() => recordCreatedId(file, { response, idMap: {} })).not.toThrow()
		expect(file.id).toBeUndefined()
	})
})

describe('countSettled', () => {
	const results = [
		{ status: 'fulfilled' },
		{ status: 'rejected' },
		{ status: 'fulfilled' },
		{ status: 'fulfilled' },
	]

	it('counts the uploads with the status asked for', () => {
		expect(countSettled(results, 'fulfilled')).toBe(3)
		expect(countSettled(results, 'rejected')).toBe(1)
	})

	it('counts nothing in an empty list', () => {
		expect(countSettled([], 'fulfilled')).toBe(0)
	})
})

describe('publicationFilesUrl', () => {
	it('points at the publication object in its register and schema', () => {
		expect(publicationFilesUrl({ registerId: '4', schemaId: '12' }, { id: 'pub-9' })).toBe(
			'/index.php/apps/openregister/api/objects/4/12/pub-9/files',
		)
	})
})

describe('notifyPublicationChanged', () => {
	beforeEach(() => library.dispatchObjectsChanged.mockClear())

	it('announces the changed publication by register, schema and id', () => {
		notifyPublicationChanged({ registerId: '4', schemaId: '12' }, { id: 'pub-9', title: 'ignored' })

		expect(library.dispatchObjectsChanged).toHaveBeenCalledTimes(1)
		expect(library.dispatchObjectsChanged).toHaveBeenCalledWith({ register: '4', schema: '12', id: 'pub-9' })
	})
})

describe('embeddedSchemaConfiguration', () => {
	it('reads the configuration of an embedded schema', () => {
		const configuration = { autoShare: true }

		expect(embeddedSchemaConfiguration({ id: 12, configuration })).toBe(configuration)
	})

	it.each([
		['a schema id', 12],
		['a schema slug', 'publication'],
		['null', null],
	])('has none for %s', (label, ref) => {
		expect(embeddedSchemaConfiguration(ref)).toBeNull()
	})

	it('is undefined for an embedded schema without one', () => {
		expect(embeddedSchemaConfiguration({ id: 12 })).toBeUndefined()
	})
})

describe('fetchSchemaConfiguration', () => {
	let fetchMock

	beforeEach(() => {
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	/**
	 * Answer the next fetch with a JSON body.
	 *
	 * @param {object} body - The body
	 * @param {boolean} ok - Whether the answer is a success
	 * @return {void}
	 */
	function answer(body, ok = true) {
		fetchMock.mockResolvedValueOnce({ ok, json: async () => body })
	}

	it.each([
		['a plain id', 12, '12'],
		['a slug', 'publication', 'publication'],
		['an embedded schema by id', { id: 7, uuid: 'u-7', slug: 's-7' }, '7'],
		['an embedded schema by uuid', { uuid: 'u-7', slug: 's-7' }, 'u-7'],
		['an embedded schema by slug', { slug: 's-7' }, 's-7'],
	])('fetches %s and returns its configuration', async (label, ref, fetchedBy) => {
		answer({ configuration: { autoShare: true } })

		await expect(fetchSchemaConfiguration(ref)).resolves.toEqual({ autoShare: true })
		expect(fetchMock).toHaveBeenCalledWith(`/index.php/apps/openregister/api/schemas/${fetchedBy}`)
	})

	it.each([
		['no reference', undefined],
		['an empty reference', ''],
		['an embedded schema without id, uuid or slug', { configuration: {} }],
	])('fetches nothing for %s', async (label, ref) => {
		await expect(fetchSchemaConfiguration(ref)).resolves.toBeNull()
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('is null when the schema cannot be fetched', async () => {
		answer({ configuration: { autoShare: true } }, false)

		await expect(fetchSchemaConfiguration(12)).resolves.toBeNull()
	})

	it('is null when the answer is not JSON', async () => {
		fetchMock.mockResolvedValueOnce({ ok: true, json: async () => { throw new SyntaxError('not json') } })

		await expect(fetchSchemaConfiguration(12)).resolves.toBeNull()
	})

	it('is null when the schema carries no configuration', async () => {
		answer({ id: 12 })

		await expect(fetchSchemaConfiguration(12)).resolves.toBeNull()
	})
})
