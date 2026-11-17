/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `unpublishedOverview.js`, the read and the rows of the two
 * unpublished dashboard widgets (publications-unpublished-overview REQ-PUO-002).
 */

import { documentRow, fetchUnpublished, publicationRow, publicationUrl } from './unpublishedOverview.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))

const ITEM = { title: 'Besluit 12', publicationId: 'p-12', catalogSlug: 'woo', publicationDate: '2026-12-01T00:00:00+00:00' }

describe('fetchUnpublished', () => {
	it('reads one list of the overview endpoint', async () => {
		const http = { get: jest.fn(async () => ({ data: { items: [ITEM], total: 1, scope: null } })) }

		await expect(fetchUnpublished('documents', http)).resolves.toEqual([ITEM])
		expect(http.get).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/dashboard/unpublished', { params: { kind: 'documents' } })
	})

	it('answers an empty list on a failed read or an answer without items', async () => {
		await expect(fetchUnpublished('publications', { get: jest.fn(async () => { throw new Error('503') }) })).resolves.toEqual([])
		await expect(fetchUnpublished('publications', { get: jest.fn(async () => ({ data: { error: 'x' } })) })).resolves.toEqual([])
		await expect(fetchUnpublished('publications', { get: jest.fn(async () => ({})) })).resolves.toEqual([])
	})
})

describe('rows and links', () => {
	it('links an item to its publication under its catalogue, and nothing without one', () => {
		expect(publicationUrl(ITEM)).toBe('/nc/apps/opencatalogi/publications/woo/p-12')
		expect(publicationUrl({ ...ITEM, catalogSlug: null })).toBeNull()
		expect(publicationUrl({ ...ITEM, publicationId: '' })).toBeNull()
		expect(publicationUrl(undefined)).toBeNull()
	})

	it('shows a publication by title with its publication date', () => {
		expect(publicationRow(ITEM)).toEqual({ id: 'p-12', mainText: 'Besluit 12', subText: '2026-12-01T00:00:00+00:00', item: ITEM })
		expect(publicationRow({ ...ITEM, publicationDate: null }).subText).toBe('')
	})

	it('shows a document by file name under its publication\'s title', () => {
		const document = { title: 'bijlage.pdf', fileId: 7, publicationTitle: 'Besluit 12', publicationId: 'p-12', catalogSlug: 'woo' }
		expect(documentRow(document)).toEqual({ id: 'p-12-7', mainText: 'bijlage.pdf', subText: 'Besluit 12', item: document })
		expect(documentRow({ ...document, publicationTitle: undefined }).subText).toBe('')
	})
})
