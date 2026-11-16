/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `RedactionLog.vue` (woo-review-surface, REQ-WRV-003): each
 * passage shows its grounds and where it sits; a passage without a ground shows empty.
 */

import RedactionLog, { groundsText } from './RedactionLog.vue'

describe('RedactionLog', () => {
	it('reads grounds as text, whatever shape OpenRegister gave them', () => {
		expect(groundsText({ grounds: ['5.1.2.e', { code: '5.1.1.d' }, { article: '5.2' }, null] })).toBe('5.1.2.e, 5.1.1.d, 5.2')
		expect(groundsText({ grounds: [] })).toBe('')
		expect(groundsText({})).toBe('')
	})

	it('places a passage by its character range', () => {
		const { position } = RedactionLog.methods

		expect(position({ positionStart: 10, positionEnd: 24 })).toBe('10–24')
		expect(position({})).toBe('')
	})
})
