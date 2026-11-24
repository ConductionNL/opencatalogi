/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The visibility answer names the archive-hotspot subject that keeps a
 * publication permanently (theme-archive-hotspot REQ-THA-003).
 *
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-3.2
 */

import { archiveHotspotOf } from './publicationVisibility.js'

describe('archiveHotspotOf', () => {
	it('answers the subject the server names, trimmed', () => {
		expect(archiveHotspotOf({ archiveHotspot: ' Herindeling gemeente ' })).toBe('Herindeling gemeente')
	})

	it('answers an empty string without a subject, for another shape or no answer', () => {
		expect(archiveHotspotOf({})).toBe('')
		expect(archiveHotspotOf({ archiveHotspot: true })).toBe('')
		expect(archiveHotspotOf(null)).toBe('')
	})
})
