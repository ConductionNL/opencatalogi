/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Only the publish route's 409 `no-documents` asks a second time (REQ-PSG-004);
 * any other conflict stays a conflict.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
 */

import { isNoDocumentsRefusal } from './publicationVisibility.js'

describe('isNoDocumentsRefusal', () => {
	it('is true for a 409 no-documents', () => {
		expect(isNoDocumentsRefusal({ response: { status: 409, data: { error: 'no-documents', documents: 0 } } })).toBe(true)
	})

	it('is false for another conflict, another status or no response', () => {
		expect(isNoDocumentsRefusal({ response: { status: 409, data: { error: 'stale' } } })).toBe(false)
		expect(isNoDocumentsRefusal({ response: { status: 409 } })).toBe(false)
		expect(isNoDocumentsRefusal({ response: { status: 423, data: { error: 'no-documents' } } })).toBe(false)
		expect(isNoDocumentsRefusal(new Error('network'))).toBe(false)
		expect(isNoDocumentsRefusal(null)).toBe(false)
	})
})
