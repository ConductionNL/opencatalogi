/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/uploadTags.js: the labels the upload dialog
 * offers. The fixture holds the label options and the store reads that
 * UploadFiles' getAllTags produced for each tags response and store shape.
 * Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import { knownTags, labelOptionLists } from '../../src/services/uploadTags.js'
import golden from './fixtures/upload-files-tags-golden.json'

/**
 * The object store a golden case describes, recording the collections read.
 *
 * @param {object} spec The store shape of the case.
 * @param {Array<string>} reads Receives the collection names read.
 * @return {object} The store.
 */
function storeOf(spec, reads) {
	if (spec.noGetCollection) {
		return {}
	}
	return {
		getCollection(name) {
			reads.push(name)
			if (spec.throwOn === name) throw new Error('get')
			if (name === 'publicationAttachments') return spec.attachments
			return name === 'tags' ? spec.stored : undefined
		},
	}
}

/**
 * The tags response the method parsed: none when the body was not JSON.
 *
 * @param {object} response The recorded response.
 * @return {unknown} The parsed body.
 */
function parsedBody(response) {
	return response.jsonThrows ? [] : response.fetched
}

const fetchedCases = golden.filter((c) => !c.response.fetchThrows)

describe('upload dialog tags (golden)', () => {
	it.each(fetchedCases.map((c) => [c.name, c]))('%s', (_name, testCase) => {
		const reads = []
		const tags = knownTags(
			parsedBody(testCase.response),
			storeOf(testCase.store, reads),
		)
		const lists = labelOptionLists(tags, 'No label')
		expect(lists.options).toEqual(testCase.state.labelOptions.options)
		expect(lists.edit).toEqual(testCase.state.labelOptionsEdit.options)
		expect(reads).toEqual(
			testCase.log
				.filter((entry) => entry[0] === 'getCollection')
				.map((entry) => entry[1]),
		)
	})
})
