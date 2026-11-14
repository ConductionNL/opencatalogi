/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the small pure helpers in src/services: file size labels,
 * object ids and display names, the listed catalog slug of a publication,
 * the theme read from the body, and the case type and retention action
 * handlers (Vitest offline suite).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createCaseTypeActionHandlers } from '../../src/services/caseTypeActions.js'
import { fileSizeLabel } from '../../src/services/fileSizeLabel.js'
import { getTheme } from '../../src/services/getTheme.js'
import { objectDisplayName } from '../../src/services/objectDisplayName.js'
import { listedCatalogSlug } from '../../src/services/publicationCatalogSlug.js'
import { resolveObjectId } from '../../src/services/resolveObjectId.js'
import { createRetentionActionHandlers } from '../../src/services/retentionActions.js'

describe('fileSizeLabel', () => {
	it('says n/a for zero bytes', () => {
		expect(fileSizeLabel(0)).toBe('n/a')
	})

	it('says under 1 KB below a kilobyte', () => {
		expect(fileSizeLabel(1)).toBe('< 1 KB')
		expect(fileSizeLabel(1023)).toBe('< 1 KB')
	})

	it('scales to KB, MB, GB and TB with one decimal', () => {
		expect(fileSizeLabel(1024)).toBe('1.0 KB')
		expect(fileSizeLabel(1536)).toBe('1.5 KB')
		expect(fileSizeLabel(5 * 1024 * 1024)).toBe('5.0 MB')
		expect(fileSizeLabel(3 * 1024 ** 3)).toBe('3.0 GB')
		expect(fileSizeLabel(2 * 1024 ** 4)).toBe('2.0 TB')
	})
})

describe('resolveObjectId', () => {
	it('prefers @self.id, then id, then uuid, then @self.uuid', () => {
		expect(resolveObjectId({ '@self': { id: 'a', uuid: 'd' }, id: 'b', uuid: 'c' })).toBe('a')
		expect(resolveObjectId({ id: 'b', uuid: 'c' })).toBe('b')
		expect(resolveObjectId({ uuid: 'c', '@self': { uuid: 'd' } })).toBe('c')
		expect(resolveObjectId({ '@self': { uuid: 'd' } })).toBe('d')
	})

	it('is null for nothing', () => {
		expect(resolveObjectId(null)).toBeNull()
		expect(resolveObjectId({})).toBeNull()
	})
})

describe('objectDisplayName', () => {
	it('prefers @self.name, then name, then @self.title, then id', () => {
		expect(objectDisplayName({ '@self': { name: 'A', title: 'T' }, name: 'B', id: 1 })).toBe('A')
		expect(objectDisplayName({ name: 'B', '@self': { title: 'T' }, id: 1 })).toBe('B')
		expect(objectDisplayName({ '@self': { title: 'T' }, id: 1 })).toBe('T')
		expect(objectDisplayName({ id: 7 })).toBe(7)
	})

	it('is undefined without an item', () => {
		expect(objectDisplayName(undefined)).toBeUndefined()
	})
})

describe('listedCatalogSlug', () => {
	const catalogs = [{ id: 1, slug: 'one' }, { id: '2', slug: 'two' }]

	it('finds the catalog by an id given as an object or a bare value, across number and string', () => {
		expect(listedCatalogSlug({ catalog: { id: '1' } }, catalogs)).toBe('one')
		expect(listedCatalogSlug({ catalog: 2 }, catalogs)).toBe('two')
	})

	it('falls back to the slug the publication carries when no catalog matches', () => {
		expect(listedCatalogSlug({ catalog: { id: 9, slug: 'own' } }, catalogs)).toBe('own')
	})

	it('is undefined when nothing names a slug', () => {
		expect(listedCatalogSlug({ catalog: 9 }, catalogs)).toBeUndefined()
	})
})

describe('getTheme', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	function body(attributes, prefersLight = false) {
		vi.stubGlobal('document', { body: { hasAttribute: (name) => attributes.includes(name) } })
		vi.stubGlobal('window', { matchMedia: (query) => ({ matches: query === '(prefers-color-scheme: light)' && prefersLight }) })
	}

	it('is light when the body forces light', () => {
		body(['data-theme-light'])
		expect(getTheme()).toBe('light')
	})

	it('follows the system preference under the default theme', () => {
		body(['data-theme-default'], true)
		expect(getTheme()).toBe('light')
		body(['data-theme-default'], false)
		expect(getTheme()).toBe('dark')
	})

	it('is dark otherwise', () => {
		body([])
		expect(getTheme()).toBe('dark')
	})
})

describe('createCaseTypeActionHandlers', () => {
	it('opens the resync dialog with the case type and entry title', () => {
		const navigationStore = { setDialog: vi.fn() }
		createCaseTypeActionHandlers({ navigationStore }).openCaseTypeResync({
			item: { title: 'Entry', formBinding: { caseType: 'ct-1' } },
		})
		expect(navigationStore.setDialog).toHaveBeenCalledWith('caseTypeResync', { caseType: 'ct-1', entryTitle: 'Entry' })
	})

	it('passes empty strings when the item lacks them', () => {
		const navigationStore = { setDialog: vi.fn() }
		createCaseTypeActionHandlers({ navigationStore }).openCaseTypeResync({})
		expect(navigationStore.setDialog).toHaveBeenCalledWith('caseTypeResync', { caseType: '', entryTitle: '' })
	})
})

describe('createRetentionActionHandlers', () => {
	function handlers() {
		const navigationStore = { setDialog: vi.fn() }
		const assign = vi.fn()
		const generateUrl = (url) => '/index.php' + url
		return { navigationStore, assign, ...createRetentionActionHandlers({ navigationStore, generateUrl, assign }) }
	}

	it('decides on one row: its id as a string and the row itself', () => {
		const h = handlers()
		const item = { id: 5, title: 'P' }
		h.openRetentionDecision({ actionId: 'archive', item })
		expect(h.navigationStore.setDialog).toHaveBeenCalledWith('retentionDecision', { decision: 'archive', ids: ['5'], publications: [item] })
	})

	it('decides on a selection: every id as a string and no rows', () => {
		const h = handlers()
		h.openRetentionDecision({ actionId: 'extend', selectedIds: [1, 'b'] })
		expect(h.navigationStore.setDialog).toHaveBeenCalledWith('retentionDecision', { decision: 'extend', ids: ['1', 'b'], publications: [] })
	})

	it('decides on nothing when neither is given', () => {
		const h = handlers()
		h.openRetentionDecision({ actionId: 'destroy' })
		expect(h.navigationStore.setDialog).toHaveBeenCalledWith('retentionDecision', { decision: 'destroy', ids: [], publications: [] })
	})

	it('downloads the retention report from its endpoint', () => {
		const h = handlers()
		h.downloadRetentionReport()
		expect(h.assign).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/retention/report')
	})
})
