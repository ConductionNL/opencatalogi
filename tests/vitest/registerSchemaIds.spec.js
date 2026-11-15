/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for registerSchemaIdsOf: the register and schema ids an
 * object names on its `@self`, embedded or by id.
 */

import { describe, expect, it } from 'vitest'
import { registerSchemaIdsOf } from '../../src/services/registerSchemaIds.js'

describe('registerSchemaIdsOf', () => {
	it.each([
		[{ register: { id: 1, uuid: 'u' }, schema: { uuid: 's' } }, { registerId: 1, schemaId: 's' }],
		[{ register: '2', schema: 3 }, { registerId: '2', schemaId: 3 }],
		[{ register: null, schema: {} }, { registerId: undefined, schemaId: undefined }],
	])('reads %j', (self, ids) => {
		expect(registerSchemaIdsOf({ '@self': self })).toEqual(ids)
	})

	it('throws without @self, as the callers did', () => {
		expect(() => registerSchemaIdsOf({})).toThrow(TypeError)
	})
})
