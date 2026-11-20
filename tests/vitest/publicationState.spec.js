/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The publication state the list and the page show comes from the server and
 * is only labelled here (REQ-PLC-002); the page offers Publish now on an
 * approved publication, and Unlist and List again to a caller with the update
 * right (REQ-PLC-006).
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-state-of-a-publication-is-one-value-the-server-computes-req-plc-002
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { PUBLICATION_FORMATTERS, publicationStateLabel } from '../../src/services/publicationState.js'
import { listActions, visibilityActions } from '../../src/services/publicationVisibility.js'

describe('publicationState', () => {
	it('labels each of the seven server states and leaves an unknown value as it is', () => {
		expect(['draft', 'in_review', 'approved', 'scheduled', 'public', 'withdrawn', 'archived'].map(publicationStateLabel))
			.toEqual(['Draft', 'In review', 'Approved', 'Scheduled', 'Public', 'Withdrawn', 'Archived'])
		expect(publicationStateLabel('elsewhere')).toBe('elsewhere')
		expect(publicationStateLabel(null)).toBe('')
	})

	it('is the formatter of the state column on the publications list, which reads the server key', () => {
		expect(PUBLICATION_FORMATTERS.publicationState('in_review')).toBe('In review')
		const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((candidate) => candidate.config?.publicationPairConfig)
		const column = page.config.publicationPairConfig.columns.find((candidate) => candidate.key === 'state')
		expect(column).toEqual({ key: 'state', label: 'State', formatter: 'publicationState' })
		const app = fs.readFileSync(path.resolve(__dirname, '../../src/App.vue'), 'utf8')
		expect(app).toContain(':formatters="formatters"')
	})
})

describe('publication page moves', () => {
	it('offers Publish now on an approved publication to a publisher', () => {
		expect(visibilityActions('approved', true).publishNow).toBe(true)
		expect(visibilityActions('approved', false).publishNow).toBe(false)
		expect(visibilityActions('in_review', true).publishNow).toBe(false)
	})

	it('offers Unlist on a listed public publication and List again on an unlisted one, with the update right only', () => {
		expect(listActions('public', false, true)).toEqual({ unlist: true, listAgain: false })
		expect(listActions('scheduled', false, true)).toEqual({ unlist: true, listAgain: false })
		expect(listActions('public', true, true)).toEqual({ unlist: false, listAgain: true })
		expect(listActions('draft', false, true)).toEqual({ unlist: false, listAgain: false })
		expect(listActions('public', false, false)).toEqual({ unlist: false, listAgain: false })
		expect(listActions('public', true, false)).toEqual({ unlist: false, listAgain: false })
	})
})
