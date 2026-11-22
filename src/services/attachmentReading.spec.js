/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the Text column and "Read again" of a publication's file list
 * (scanned-attachments-become-searchable REQ-SOCR-004).
 */

import {
	fetchAttachmentReadings,
	readingLabel,
	readingReason,
	requestReadAgain,
} from './attachmentReading.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/nc' + path.replace(/{(\w+)}/g, (_, k) => params[k]),
}))

/**
 * A fetch double answering one response.
 *
 * @param {number} status - The HTTP status
 * @param {object} body - The JSON body
 * @return {Function} The fetch
 */
function answering(status, body) {
	return jest.fn(async () => ({ ok: status < 400, status, json: async () => body }))
}

describe('attachmentReading', () => {
	it('names every reading state of design D4', () => {
		expect(['typed', 'ocr', 'waiting', 'none', 'unavailable', 'failed'].map((state) => readingLabel({ state }))).toEqual([
			'Typed text',
			'Read by OCR',
			'Reading',
			'No text found',
			'No text: OCR is not installed',
			'Failed',
		])
	})

	it('leaves the column empty for an attachment that was not read or an unknown state', () => {
		expect(readingLabel(undefined)).toBe('')
		expect(readingLabel(null)).toBe('')
		expect(readingLabel({ state: 'something' })).toBe('')
	})

	it('gives the reason as the tooltip, unless it only repeats the label', () => {
		expect(readingReason({ state: 'failed', reason: 'OCR did not complete' })).toBe('OCR did not complete')
		expect(readingReason({ state: 'none', reason: 'No text found' })).toBe('')
		expect(readingReason({ state: 'typed' })).toBe('')
		expect(readingReason(undefined)).toBe('')
	})

	it('reads the states of a publication keyed by file id', async () => {
		const fetchFn = answering(200, {
			readings: [
				{ fileId: 11, state: 'typed' },
				{ fileId: 12, state: 'unavailable', reason: 'OCR is not installed' },
				null,
			],
			canReadAgain: true,
		})

		const result = await fetchAttachmentReadings('pub-1', fetchFn)

		expect(fetchFn).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/publications/pub-1/attachment-readings', {
			headers: { Accept: 'application/json' },
		})
		expect(result).toEqual({
			readings: {
				11: { fileId: 11, state: 'typed' },
				12: { fileId: 12, state: 'unavailable', reason: 'OCR is not installed' },
			},
			canReadAgain: true,
		})
	})

	it('shows no states and no Read again when the read fails', async () => {
		const empty = { readings: {}, canReadAgain: false }
		expect(await fetchAttachmentReadings('pub-1', answering(503, { error: 'not-configured' }))).toEqual(empty)
		expect(await fetchAttachmentReadings('pub-1', jest.fn(async () => { throw new Error('offline') }))).toEqual(empty)
		expect(await fetchAttachmentReadings('pub-1', answering(200, { readings: 'nope' }))).toEqual(empty)

		const unused = jest.fn()
		expect(await fetchAttachmentReadings('', unused)).toEqual(empty)
		expect(unused).not.toHaveBeenCalled()
	})

	it('asks for a new reading with the request token', async () => {
		const fetchFn = answering(200, { fileId: 12, queued: true })

		expect(await requestReadAgain('pub-1', 12, 'tok', fetchFn)).toEqual({ ok: true, message: '' })
		expect(fetchFn).toHaveBeenCalledWith(
			'/nc/apps/opencatalogi/api/publications/pub-1/attachment-readings/12/read-again',
			{ method: 'POST', headers: { Accept: 'application/json', requesttoken: 'tok' } },
		)
	})

	it('says why a new reading was refused', async () => {
		expect(await requestReadAgain('pub-1', 12, 'tok', answering(403, { message: 'You may not change this publication.' }))).toEqual({
			ok: false,
			message: 'You may not change this publication.',
		})
		expect(await requestReadAgain('pub-1', 12, 'tok', answering(500, {}))).toEqual({
			ok: false,
			message: 'The attachment could not be queued for reading.',
		})
		const brokenJson = jest.fn(async () => ({ ok: false, status: 502, json: async () => { throw new Error('html') } }))
		expect((await requestReadAgain('pub-1', 12, 'tok', brokenJson)).message).toBe('The attachment could not be queued for reading.')
		expect((await requestReadAgain('pub-1', 12, 'tok', jest.fn(async () => { throw new Error('offline') }))).ok).toBe(false)
	})
})
