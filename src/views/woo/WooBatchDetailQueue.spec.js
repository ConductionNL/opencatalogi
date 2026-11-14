/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the document queue on `WooBatchDetail.vue`
 * (woo-document-assessment-screen, REQ-WDA-002): the assessments are read
 * through the object store, a move into partly or not public asks the grounds
 * first, a keyboard move saves and is announced, and a refused save leaves the
 * card where it was with the refusal on it.
 */

import axios from '@nextcloud/axios'
import WooBatchDetail from './WooBatchDetail.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m),
}))
jest.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key) =>
		({ woo_register: '7', woo_assessment_schema: '42' })[key],
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../dialogs/woo/WooAssessmentDialog.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	objectStore: {
		registerObjectType: jest.fn(),
		fetchCollection: jest.fn(),
		getCollection: jest.fn(),
	},
}))

/**
 * A component state with the methods and computed values bound.
 *
 * @param {object} overrides The state to start from.
 * @return {object} The state.
 */
function state(overrides = {}) {
	const s = {
		...WooBatchDetail.data(),
		...WooBatchDetail.methods,
		batchId: 'batch-1',
		batch: { caseReference: 'WOO-2026-031', documents: ['a1', 'a2'] },
		...overrides,
	}
	Object.defineProperty(s, 'columns', {
		get: () => WooBatchDetail.computed.columns.call(s),
	})
	return s
}

const toAssess = {
	id: 'a1',
	assessment: 'te_beoordelen',
	fileName: 'Email thread with residents',
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('the queue', () => {
	it('reads the batch assessments through the object store', async () => {
		objectStore.getCollection.mockReturnValue({
			results: [
				toAssess,
				{ id: 'a2', assessment: 'openbaar' },
				{ id: 'x9', assessment: 'openbaar' },
			],
		})
		const s = state()

		await s.loadAssessments()

		expect(objectStore.registerObjectType).toHaveBeenCalledWith(
			'wooAssessment',
			'42',
			'7',
		)
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('wooAssessment', {
			caseReference: 'WOO-2026-031',
		})
		expect(s.assessments.map((a) => a.id)).toEqual(['a1', 'a2'])
		expect(s.columns.map((c) => [c.label, c.items.length])).toEqual([
			['To assess', 1],
			['Public', 1],
			['Partly public', 0],
			['Not public', 0],
		])
	})

	it('asks the grounds before a partly public move, and saves nothing without them', () => {
		const s = state({ assessments: [toAssess] })

		s.requestMove(toAssess, 'deels_openbaar')

		expect(s.dialog).toEqual({ item: toAssess, target: 'deels_openbaar' })
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('saves the grounds the dialog returns and shows the redaction state', async () => {
		axios.put.mockResolvedValue({
			data: {
				id: 'a1',
				assessment: 'deels_openbaar',
				weigeringsgronden: ['5.1.2.e'],
				redactionStatus: 'verified',
			},
		})
		axios.get.mockResolvedValue({ data: { caseReference: 'WOO-2026-031' } })
		const s = state({
			assessments: [toAssess],
			dialog: { item: toAssess, target: 'deels_openbaar' },
		})

		await s.confirmGrounds(['5.1.2.e'])

		expect(axios.put).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/batch-1/documents/a1',
			{ assessment: 'deels_openbaar', weigeringsgronden: ['5.1.2.e'] },
		)
		expect(s.dialog).toBeNull()
		expect(s.columns[2].items.map((i) => i.id)).toEqual(['a1'])
		expect(s.redactionLabel(s.assessments[0])).toBe('Redacted, not verified')
	})

	it('saves a keyboard move to public at once and announces it', async () => {
		axios.put.mockResolvedValue({ data: { id: 'a1', assessment: 'openbaar' } })
		axios.get.mockResolvedValue({ data: { caseReference: 'WOO-2026-031' } })
		const s = state({ assessments: [toAssess] })

		await s.requestMove(toAssess, 'openbaar')

		expect(axios.put).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/batch-1/documents/a1',
			{ assessment: 'openbaar', weigeringsgronden: [] },
		)
		expect(s.announcement).toBe('Email thread with residents moved to Public')
		expect(s.columns[1].items.map((i) => i.id)).toEqual(['a1'])
	})

	it('keeps a refused card in its column and shows the refusal on it', async () => {
		axios.put.mockRejectedValue({
			response: { data: { error: 'Unknown weigeringsgrond: x' } },
		})
		const s = state({ assessments: [toAssess] })

		await s.saveMove(toAssess, 'niet_openbaar', ['x'])

		expect(s.columns[0].items.map((i) => i.id)).toEqual(['a1'])
		expect(s.cardErrors.a1).toBe('Unknown weigeringsgrond: x')
		expect(s.saving.a1).toBe(false)
	})

	it('moves a dropped card through the same path', () => {
		const s = state({ assessments: [toAssess] })

		s.onDragStart(toAssess)
		s.onDrop('niet_openbaar')

		expect(s.dialog).toEqual({ item: toAssess, target: 'niet_openbaar' })
		expect(s.dragged).toBeNull()
	})

	it('links a numeric document reference to its file', () => {
		const s = state()

		expect(s.previewUrl({ documentReference: '123' })).toBe('/f/123')
		expect(s.previewUrl({ documentReference: 'docs/a.pdf' })).toBe('')
	})
})
