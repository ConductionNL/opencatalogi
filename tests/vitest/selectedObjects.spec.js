/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/selectedObjects.js: which objects
 * SelectedObjectsList shows. The fixture holds what the component's
 * selectedObjects computed returned for each props and store shape.
 * Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	resolveSelectedObjects,
	selectedAttachmentObjects,
} from '../../src/services/selectedObjects.js'
import golden from './fixtures/selected-objects-golden.json'

/**
 * The object store a golden case describes, with a recording getCollection
 * when the case has one.
 *
 * @param {object} testCase A golden case.
 * @param {Array<string>} calls Receives the collection names asked for.
 * @return {object} The store.
 */
function storeOf(testCase, calls) {
	const { coll, getCollection, ...store } = testCase.store
	if (getCollection) {
		store.getCollection = (name) => {
			calls.push(name)
			return coll
		}
	}
	return store
}

describe('resolveSelectedObjects (golden)', () => {
	it.each(golden.map((c) => [c.name, c]))('%s', (_name, testCase) => {
		const calls = []
		const store = storeOf(testCase, calls)
		expect(resolveSelectedObjects(testCase.props.objects, store)).toEqual(
			testCase.result,
		)
		expect(calls).toEqual(testCase.getCollectionCalls)
	})
})

describe('selectedObjects identity', () => {
	it('returns the passed objects and the store selection as they are', () => {
		const objects = [{ id: 'o1' }]
		const store = { selectedObjects: [{ id: 's1' }] }
		expect(resolveSelectedObjects(objects, store)).toBe(objects)
		expect(resolveSelectedObjects(null, store)).toBe(store.selectedObjects)
	})

	it('gives no attachments when none are selected', () => {
		expect(selectedAttachmentObjects({ selectedAttachments: null })).toBeNull()
	})
})
