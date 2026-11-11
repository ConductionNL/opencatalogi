/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for `ViewObject.vue`'s hasSchemaReadRight, which decides which
 * schemas the schema dropdown offers: every allowed and every refused case.
 */

import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))
jest.mock('../../store/store.js', () => ({ navigationStore: {}, objectStore: {}, catalogStore: {} }))

/**
 * Whether a user in `groups` may read `schema`.
 *
 * @param {Array<string>|null} groups The user's groups, null while loading.
 * @param {object} schema The schema.
 * @return {boolean} The answer.
 */
function canRead(groups, schema) {
	return ViewObject.methods.hasSchemaReadRight.call({ currentUserGroups: groups }, schema)
}

const CASES = [
	// [name, groups, authorization, allowed]
	['groups still loading', null, { read: ['editors'] }, true],
	['no authorization', ['users'], undefined, true],
	['authorization without read', ['users'], { write: ['editors'] }, true],
	['read that is not a list', ['users'], { read: 'editors' }, true],
	['an empty read list', ['users'], { read: [] }, true],
	['an admin', ['admin'], { read: ['editors'] }, true],
	['a member of a read group', ['users', 'editors'], { read: ['editors'] }, true],
	['not a member of any read group', ['users'], { read: ['editors', 'owners'] }, false],
	['a member of an object entry group', ['editors'], { read: [{ group: 'editors', match: { a: 1 } }] }, true],
	['not a member of an object entry group', ['users'], { read: [{ group: 'editors' }] }, false],
	['an object entry without group', ['users'], { read: [{ match: { a: 1 } }] }, true],
	['a null entry', ['users'], { read: [null] }, true],
	['a number entry', ['users'], { read: [5] }, true],
	['one refusing and one allowing entry', ['users'], { read: ['editors', { group: 'users' }] }, true],
	['only refusing entries', ['users'], { read: ['editors', { group: 'owners' }] }, false],
	['no groups at all', [], { read: ['editors'] }, false],
]

describe('ViewObject hasSchemaReadRight', () => {
	it.each(CASES)('%s', (name, groups, authorization, allowed) => {
		expect(canRead(groups, { authorization })).toBe(allowed)
	})
})
