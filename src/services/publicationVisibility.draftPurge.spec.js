/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * When the page offers Delete permanently, and how a refused or stopped
 * permanent delete is put into words (REQ-PLC-004).
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
 */

import { canDeletePermanently, draftPurgeMessage } from './publicationVisibility.js'

describe('canDeletePermanently', () => {
	it('offers it for a draft or a publication in review to a caller who may update it', () => {
		expect(canDeletePermanently('draft', true)).toBe(true)
		expect(canDeletePermanently('in_review', true)).toBe(true)
	})

	it('does not offer it in any other state, or without the update right', () => {
		for (const state of ['approved', 'scheduled', 'public', 'withdrawn', 'archived', '']) {
			expect(canDeletePermanently(state, true)).toBe(false)
		}
		expect(canDeletePermanently('draft', false)).toBe(false)
		expect(canDeletePermanently('draft', undefined)).toBe(false)
	})
})

describe('draftPurgeMessage', () => {
	it('gives the refusal in the server words', () => {
		const error = {
			response: {
				data: {
					error: 'released-before',
					message: 'This publication was published before, so it cannot be deleted permanently.',
				},
			},
		}
		expect(draftPurgeMessage(error)).toBe(
			'This publication was published before, so it cannot be deleted permanently.',
		)
	})

	it('names what was already removed when the delete stopped part way', () => {
		const error = {
			response: {
				data: {
					error: 'purge-incomplete',
					message: 'Deleting stopped part way. What was removed is listed.',
					removed: [
						{ kind: 'file', id: '11', name: 'Besluit.pdf' },
						{ kind: 'documentReference', id: 'ref-1', name: '' },
					],
				},
			},
		}
		expect(draftPurgeMessage(error)).toBe(
			'Deleting stopped part way. What was removed is listed. Besluit.pdf, ref-1',
		)
	})

	it('keeps the sentence alone when the stop removed nothing', () => {
		const error = {
			response: { data: { error: 'purge-incomplete', message: 'Stopped.', removed: [] } },
		}
		expect(draftPurgeMessage(error)).toBe('Stopped.')
	})

	it('gives nothing without a server sentence', () => {
		expect(draftPurgeMessage({})).toBe('')
		expect(draftPurgeMessage(null)).toBe('')
		expect(draftPurgeMessage({ response: { data: { error: 'x' } } })).toBe('')
	})
})
