/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/catalogMetadata.js: the metadata rows of the
 * catalog detail page. The fixture holds what CatalogDetailPage's
 * metadataItems computed returned for each catalog shape, with dates rendered
 * as D[<ISO>] so the output does not depend on the locale. Offline, no DOM.
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { catalogMetadataItems } from '../../src/services/catalogMetadata.js'
import golden from './fixtures/catalog-metadata-items-golden.json'

const toLocaleString = Date.prototype.toLocaleString

beforeAll(() => {
	Date.prototype.toLocaleString = function () {
		return 'D[' + this.toISOString() + ']'
	}
})

afterAll(() => {
	Date.prototype.toLocaleString = toLocaleString
})

describe('catalogMetadataItems (golden)', () => {
	it.each(golden.map((c) => [c.name, c]))('%s', (_name, testCase) => {
		expect(catalogMetadataItems(testCase.catalog)).toEqual(testCase.items)
	})
})
