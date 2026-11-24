/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The licence list the pickers offer (REQ-DCC-001).
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

import axios from '@nextcloud/axios'
import { fetchLicences, forgetLicences, licenceOptionFor, licenceOptions } from './licences.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const CC0 = 'http://publications.europa.eu/resource/authority/licence/CC0'
const BY = 'http://publications.europa.eu/resource/authority/licence/CC_BY_4_0'
const ROWS = [
	{ code: 'CC_BY_4_0', iri: BY, label: 'Creative Commons Attribution 4.0', labelNl: 'Creative Commons Naamsvermelding 4.0' },
	{ code: 'CC0', iri: CC0, label: 'Creative Commons Zero', labelNl: 'Creative Commons Zero' },
	{ code: 'BROKEN', iri: '', label: 'No IRI' },
]

beforeEach(() => {
	forgetLicences()
	axios.get.mockReset()
})

describe('fetchLicences', () => {
	it('reads the list once for every picker on the page', async () => {
		axios.get.mockResolvedValue({ data: { results: ROWS } })

		const [first, second] = await Promise.all([fetchLicences(), fetchLicences()])

		expect(first).toBe(ROWS)
		expect(second).toBe(ROWS)
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/dcat/licences')
	})

	it('reads again after a failed read', async () => {
		axios.get.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ data: { results: ROWS } })

		await expect(fetchLicences()).rejects.toThrow('offline')
		await expect(fetchLicences()).resolves.toBe(ROWS)
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('answers an empty list when the body carries none', async () => {
		axios.get.mockResolvedValue({ data: {} })

		await expect(fetchLicences()).resolves.toEqual([])
	})
})

describe('licenceOptions', () => {
	it('stores the IRI, labels in Dutch for a Dutch reader, sorts by label and drops a member without an IRI', () => {
		expect(licenceOptions(ROWS, 'nl')).toEqual([
			{ id: BY, code: 'CC_BY_4_0', label: 'Creative Commons Naamsvermelding 4.0' },
			{ id: CC0, code: 'CC0', label: 'Creative Commons Zero' },
		])
	})

	it('labels in English for any other reader', () => {
		expect(licenceOptions(ROWS, 'en').map((option) => option.label)).toEqual([
			'Creative Commons Attribution 4.0',
			'Creative Commons Zero',
		])
	})

	it('answers no options for no list', () => {
		expect(licenceOptions(null, 'nl')).toEqual([])
	})
})

describe('licenceOptionFor', () => {
	const options = licenceOptions(ROWS, 'en')

	it('finds the option by IRI, code or label, ignoring case', () => {
		expect(licenceOptionFor(options, CC0).code).toBe('CC0')
		expect(licenceOptionFor(options, 'cc0').code).toBe('CC0')
		expect(licenceOptionFor(options, 'creative commons zero').code).toBe('CC0')
	})

	it('answers null for an empty value or one not on the list', () => {
		expect(licenceOptionFor(options, '')).toBeNull()
		expect(licenceOptionFor(options, null)).toBeNull()
		expect(licenceOptionFor(options, 'vrij te gebruiken')).toBeNull()
	})
})
