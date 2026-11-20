/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edit form's relation fields (publication-relations-place-and-source-ids
 * task 1.4, REQ-PRS-001): `replaces`, `amends`, `implements` and `relatedTo`
 * are pickers over the publications of the same register and schema.
 */

import ViewObject from './ViewObject.vue'
import { isSelfRelationProperty, refTarget, relationFieldOverrides } from './relationFields.js'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-request-token' }))
jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		getActiveObject: () => null,
		getCollection: () => ({ results: [{ id: 't1', title: 'Theme one' }] }),
	},
}))

/**
 * A relation property as the register fragment declares it.
 *
 * @param {string} type The relation type key.
 * @param {string} ref The `$ref` of the items.
 * @return {object} The property.
 */
function relation(type, ref = 'publication') {
	return {
		type: 'array',
		items: { type: 'string', format: 'uuid', $ref: ref, 'x-openregister-relation': { type } },
	}
}

const publicationSchema = {
	id: 12,
	slug: 'publication',
	properties: {
		title: { type: 'string' },
		themes: { type: 'array', items: { type: 'string', $ref: 'theme' } },
		organization: { type: 'string', $ref: 'organization' },
		replaces: relation('replaces'),
		amends: relation('amends'),
		implements: relation('implements'),
		relatedTo: relation('relatedTo', '#/components/schemas/publication'),
		mentions: relation('mentions', 'organization'),
	},
}

const picker = {
	widget: 'select',
	selectMultiple: true,
	optionsFrom: { register: '7', schema: '12', labelField: 'title' },
}

describe('refTarget', () => {
	it('takes the last segment of a $ref', () => {
		expect(refTarget('#/components/schemas/publication')).toBe('publication')
		expect(refTarget(' publication ')).toBe('publication')
		expect(refTarget(undefined)).toBe('')
		expect(refTarget('')).toBe('')
	})
})

describe('isSelfRelationProperty', () => {
	it('accepts an array of relations to the own schema only', () => {
		expect(isSelfRelationProperty(relation('replaces'), 'publication')).toBe(true)
		expect(isSelfRelationProperty(relation('mentions', 'organization'), 'publication')).toBe(false)
		expect(isSelfRelationProperty(publicationSchema.properties.themes, 'publication')).toBe(false)
		expect(isSelfRelationProperty({ type: 'string', $ref: 'publication' }, 'publication')).toBe(false)
		expect(isSelfRelationProperty(relation('replaces'), '')).toBe(false)
		expect(isSelfRelationProperty({ type: 'array', items: { $ref: 'publication', 'x-openregister-relation': {} } }, 'publication')).toBe(false)
	})
})

describe('relationFieldOverrides', () => {
	it('makes each of the four relation properties a picker over the same register and schema', () => {
		expect(relationFieldOverrides(publicationSchema, 7)).toEqual({
			replaces: picker,
			amends: picker,
			implements: picker,
			relatedTo: picker,
		})
	})

	it('offers nothing while the register or schema is unknown', () => {
		expect(relationFieldOverrides(publicationSchema, null)).toEqual({})
		expect(relationFieldOverrides(publicationSchema, '')).toEqual({})
		expect(relationFieldOverrides({ ...publicationSchema, id: undefined }, 7)).toEqual({})
		expect(relationFieldOverrides(null, 7)).toEqual({})
	})
})

describe('ViewObject propertyOverrides', () => {
	/**
	 * Evaluate propertyOverrides on a plain context.
	 *
	 * @param {object} state The context.
	 * @return {object} The overrides.
	 */
	function overrides(state) {
		const ctx = { ...state }
		Object.defineProperty(ctx, 'themeOptions', { get: () => ViewObject.computed.themeOptions.call(ctx) })
		return ViewObject.computed.propertyOverrides.call(ctx)
	}

	it('reads the register of an existing publication from its @self', () => {
		const result = overrides({
			isNewObject: false,
			resolvedSchema: publicationSchema,
			currentObject: { '@self': { id: 'p1', register: 7 } },
			selectedRegister: { id: 99 },
		})
		expect(result.replaces).toEqual(picker)
		expect(result.relatedTo).toEqual(picker)
		expect(result.themes).toEqual({ widget: 'select', selectOptions: [{ id: 't1', label: 'Theme one' }], selectMultiple: true })
		expect(result.mentions).toBeUndefined()
	})

	it('reads the register of a new publication from the selected register', () => {
		const result = overrides({
			isNewObject: true,
			resolvedSchema: publicationSchema,
			currentObject: null,
			selectedRegister: { id: 7 },
		})
		expect(result.amends).toEqual(picker)
	})

	it('keeps only the themes override before a register is chosen', () => {
		const result = overrides({ isNewObject: true, resolvedSchema: publicationSchema, currentObject: null, selectedRegister: null })
		expect(Object.keys(result)).toEqual(['themes'])
	})
})
