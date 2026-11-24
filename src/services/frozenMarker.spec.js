/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The frozen marker as the publication page reads it, and the page condition
 * that makes a frozen publication read-only (REQ-PWA-003).
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.4
 */

import manifest from '../manifest.json'
import { FROZEN_READ_ONLY_WHEN, frozenMarker } from './frozenMarker.js'

const MARKER = {
	by: 'admin',
	at: '2026-11-20T09:00:00+00:00',
	reason: 'Bevat een woonadres',
	state: 'withdrawn',
}

/**
 * Find a page of the manifest by its id.
 *
 * @param {string} id The page id.
 * @return {object|undefined} The page.
 */
function page(id) {
	return manifest.pages.find((candidate) => candidate.id === id)
}

describe('frozenMarker', () => {
	it('reads the marker OpenRegister keeps under @self', () => {
		expect(frozenMarker({ id: 'pub-1', '@self': { frozen: MARKER } })).toEqual(
			MARKER,
		)
	})

	it('reads a marker at the top of the record as well', () => {
		expect(
			frozenMarker({
				frozen: { by: 'admin', at: '2026-11-20T09:00:00+00:00' },
			}),
		).toEqual({
			by: 'admin',
			at: '2026-11-20T09:00:00+00:00',
			reason: '',
			state: '',
		})
	})

	it('says a record without a marker, or with an empty one, is not frozen', () => {
		expect(frozenMarker(null)).toBeNull()
		expect(frozenMarker({ '@self': {} })).toBeNull()
		expect(frozenMarker({ '@self': { frozen: null } })).toBeNull()
		expect(frozenMarker({ '@self': { frozen: {} } })).toBeNull()
		expect(frozenMarker({ '@self': { frozen: [] } })).toBeNull()
	})

	it('keeps only text fields, so a damaged marker still reads', () => {
		expect(
			frozenMarker({
				'@self': { frozen: { by: 7, at: '  2026-11-20  ', reason: null } },
			}),
		).toEqual({ by: '', at: '2026-11-20', reason: '', state: '' })
	})
})

describe('the publication page of a frozen publication', () => {
	it('is read-only while @self.frozen holds a marker', () => {
		expect(page('PublicationDetail').config.readOnlyWhen).toEqual(
			FROZEN_READ_ONLY_WHEN,
		)
	})
})
