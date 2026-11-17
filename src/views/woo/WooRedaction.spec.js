/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `WooRedaction.vue` (woo-document-assessment-screen, REQ-WDA-003
 * and REQ-WDA-004): the assessment is read through the object store, the
 * findings of its redacted file from OpenRegister, the two versions switch,
 * and Mark redaction verified stores the officer or shows the refusal.
 */

import axios from '@nextcloud/axios'
import WooRedaction from './WooRedaction.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
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
jest.mock('../../store/store.js', () => ({
	objectStore: {
		registerObjectType: jest.fn(),
		fetchObject: jest.fn(),
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
		...WooRedaction.data(),
		...WooRedaction.methods,
		batchId: 'batch-1',
		docId: 'a1',
		...overrides,
	}
	for (const name of ['findings', 'steps', 'state', 'canVerify', 'shownFileId']) {
		Object.defineProperty(s, name, {
			get: () => WooRedaction.computed[name].call(s),
		})
	}
	return s
}

const unchecked = {
	id: 'a1',
	assessment: 'deels_openbaar',
	fileName: 'Email thread with residents',
	documentReference: '11',
	anonymizedDocument: '12',
	redactionStatus: 'verified',
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('the redaction page', () => {
	it('reads the assessment and the findings of its redacted file', async () => {
		objectStore.fetchObject.mockResolvedValue(unchecked)
		axios.get.mockResolvedValue({
			data: {
				results: [
					{
						id: 1,
						entityId: 10,
						page: 1,
						skipAnonymization: false,
						bases: ['5.1.2e'],
					},
					{
						id: 2,
						entityId: 20,
						page: 1,
						skipAnonymization: false,
						bases: ['5.1.2e'],
					},
					{
						id: 3,
						entityId: 30,
						page: 2,
						skipAnonymization: true,
						bases: null,
					},
				],
			},
		})
		const s = state()

		await s.load()

		expect(objectStore.registerObjectType).toHaveBeenCalledWith(
			'wooAssessment',
			'42',
			'7',
		)
		expect(objectStore.fetchObject).toHaveBeenCalledWith('wooAssessment', 'a1')
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/entity-relations',
			{
				params: { fileId: '12' },
			},
		)
		expect([
			s.findings.kept,
			s.findings.rejected,
			s.findings.rows.length,
		]).toEqual([2, 1, 3])
		expect(s.findingUrl(s.findings.rows[0])).toBe(
			'/apps/openregister/entities/10',
		)
		expect(s.findingState(s.findings.rows[2])).toBe('Rejected')
		expect(s.steps.map((step) => [step.label, step.done])).toEqual([
			['Original kept, never published', true],
			['Redacted version generated', true],
			['Verified', false],
		])
		expect(s.canVerify).toBe(true)
	})

	it('reads no findings while there is no redacted file', async () => {
		objectStore.fetchObject.mockResolvedValue({
			...unchecked,
			anonymizedDocument: '',
			redactionStatus: 'failed',
		})
		const s = state()

		await s.load()

		expect(axios.get).not.toHaveBeenCalled()
		expect(s.findings.rows).toEqual([])
		expect(s.canVerify).toBe(false)
	})

	it('shows the redacted version first and the original on request', () => {
		const s = state({ assessment: unchecked })

		expect(s.shownFileId).toBe('12')
		s.version = 'original'
		expect(s.shownFileId).toBe('11')
		expect(s.fileUrl(s.shownFileId)).toBe('/f/11')
	})

	it('stores the officer when the redaction is marked verified', async () => {
		axios.post.mockResolvedValue({
			data: {
				...unchecked,
				redactionVerifiedBy: 'anne',
				redactionVerifiedAt: '2026-11-14T10:00:00+00:00',
			},
		})
		const s = state({ assessment: unchecked })

		await s.verify()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/batch-1/documents/a1/verify-redaction',
		)
		expect(s.state).toBe('checked')
		expect(s.canVerify).toBe(false)
		expect(s.steps[2]).toMatchObject({
			done: true,
			by: 'anne',
			at: '2026-11-14T10:00:00+00:00',
		})
		expect(s.verifying).toBe(false)
	})

	it('shows the refusal and keeps the redaction unverified', async () => {
		axios.post.mockRejectedValue({
			response: { data: { error: 'Redaction status is failed' } },
		})
		const s = state({ assessment: unchecked })

		await s.verify()

		expect(s.verifyError).toBe('Redaction status is failed')
		expect(s.state).toBe('unchecked')
		expect(s.verifying).toBe(false)
	})
})
