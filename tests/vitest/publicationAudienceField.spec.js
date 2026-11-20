/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The publication page lets an editor say who can read a publication
 * (publications-for-signed-in-readers, REQ-PSR-001): the editable Publication
 * Data widget includes `audience` and `minimumAssurance`, right under the
 * dates, and both are properties the shipped schema fragment declares.
 *
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-publication-says-who-can-read-it-req-psr-001
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))

/**
 * Every Data widget with id pub-data in the manifest.
 *
 * @param {object} node A manifest node.
 * @param {Array<object>} found The widgets found so far.
 * @return {Array<object>} The widgets.
 */
function dataWidgets(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => dataWidgets(child, found))
	} else if (node && typeof node === 'object') {
		if (node.id === 'pub-data' && node.type === 'data') {
			found.push(node)
		}
		Object.values(node).forEach((child) => dataWidgets(child, found))
	}
	return found
}

describe('the publication page edits who can read it', () => {
	const [widget] = dataWidgets(readJson(path.join(ROOT, 'src/manifest.json')))
	const include = widget.content.include
	const fragment = readJson(path.join(ROOT, 'lib/Settings/register.d/publications-for-signed-in-readers.json'))
	const properties = fragment.components.schemas.publication.properties

	it('lists audience and the minimum level right after the dates', () => {
		const at = include.indexOf('depublicationDate')
		expect(include.slice(at + 1, at + 3)).toEqual(['audience', 'minimumAssurance'])
	})

	it('names properties the schema declares, with labels for every choice', () => {
		expect(properties.audience.enum).toEqual(['public', 'signedIn'])
		expect(Object.keys(properties.audience['x-enum-labels'])).toEqual(['public', 'signedIn'])
		expect(Object.keys(properties.minimumAssurance['x-enum-labels'])).toEqual(['substantial', 'high'])
		expect(widget.content.overrides?.audience?.editable).not.toBe(false)
	})
})
