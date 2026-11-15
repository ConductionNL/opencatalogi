/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/publicationVisibility.js: the files route and
 * the withdrawal documents of PublicationVisibilityWidget. The fixture holds
 * the requests the widget's load() made and the documents it kept, for each
 * props, context and response shape. Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	documentOptions,
	filesRouteParams,
} from '../../src/services/publicationVisibility.js'
import golden from './fixtures/publication-visibility-load-golden.json'

const FILES_ROUTE = '/apps/openregister/api/objects/{register}/{schema}/{id}/files '

/**
 * The documents load() keeps for a files response: none when the request or
 * the mapping fails.
 *
 * @param {{throw?: boolean, data?: object}} response The recorded files response.
 * @return {Array<object>} The documents.
 */
function documentsFor(response) {
	if (response.throw) {
		return []
	}
	try {
		return documentOptions(response.data)
	} catch {
		return []
	}
}

describe('publication visibility load (golden)', () => {
	it.each(golden.cases.map((c) => [c.name, c]))('%s', (_name, testCase) => {
		const filesCall = testCase.calls.find((url) => url.startsWith(FILES_ROUTE))
		expect(filesRouteParams({ ...testCase.props, id: 'pub-1' })).toEqual(
			JSON.parse(filesCall.slice(FILES_ROUTE.length)),
		)
		expect(documentsFor(testCase.responses.files)).toEqual(testCase.documents)
	})
})

describe('documentOptions', () => {
	it('labels a file by its title, else its name, else its id', () => {
		expect(documentOptions(golden.files)).toEqual([
			{ id: 1, label: 'T1' },
			{ id: 2, label: 'n2' },
			{ id: 3, label: '3' },
			{ id: 0, label: '0' },
		])
	})
})
