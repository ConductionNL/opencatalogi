/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/catalogForm.js: the values the catalog modal
 * starts from when it edits a catalog. The fixture holds the state that
 * CatalogModal's updated hook left for each catalog shape. Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	catalogFormFromObject,
	catalogOrganizationOption,
	idOptions,
} from '../../src/services/catalogForm.js'
import golden from './fixtures/catalog-modal-prefill-golden.json'

const editCases = golden.cases.filter((c) => c.state.catalogi !== 'untouched')

describe('catalog modal prefill (golden)', () => {
	it.each(editCases.map((c) => [c.name, c]))('%s', (_name, testCase) => {
		const catalog = testCase.input.catalog
		expect(catalogFormFromObject(catalog, golden.statusOptions)).toEqual(
			testCase.state.catalogi,
		)
		expect(catalogOrganizationOption(catalog, golden.organizations)).toEqual(
			testCase.state.selectedOrganization,
		)
		expect(idOptions(catalog.registers, golden.registers)).toEqual(
			testCase.state.selectedRegisters,
		)
		expect(idOptions(catalog.schemas, golden.schemas)).toEqual(
			testCase.state.selectedSchemas,
		)
	})

	it('covers every edit case of the fixture', () => {
		expect(editCases).toHaveLength(7)
	})
})
