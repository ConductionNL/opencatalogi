/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for `ViewObject.vue`'s getRegisterSchemaIds: the
 * register and schema ids it reads from an object's `@self`.
 */

import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))

jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: { availableRegisters: [], availableSchemas: [] },
}))

describe('ViewObject getRegisterSchemaIds', () => {
	it.each([
		[{ register: { id: 1, uuid: 'u' }, schema: { uuid: 's' } }, { registerId: 1, schemaId: 's' }],
		[{ register: '2', schema: 3 }, { registerId: '2', schemaId: 3 }],
		[{ register: null, schema: {} }, { registerId: undefined, schemaId: undefined }],
	])('reads %j', (self, ids) => {
		expect(ViewObject.methods.getRegisterSchemaIds({ '@self': self })).toEqual(ids)
	})
})
