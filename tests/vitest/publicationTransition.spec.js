/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The browser publish of a publication runs OpenRegister's transition route
 * with the publication date in the same call (publication-lifecycle-on-or
 * REQ-PLC-003); an object without a stored lifecycle state, or one already
 * published, keeps the plain date write. The mass publish dialog uses it.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	publishActionFor,
	publishesThroughLifecycle,
	publishThroughLifecycle,
} from '../../src/services/publicationTransition.js'

afterEach(() => {
	vi.restoreAllMocks()
})

describe('publicationTransition', () => {
	it('runs a transition only for an object with a stored state that is not yet published', () => {
		expect(publishesThroughLifecycle({ status: 'approved' })).toBe(true)
		expect(publishesThroughLifecycle({ status: 'draft' })).toBe(true)
		expect(publishesThroughLifecycle({ status: '' })).toBe(true)
		expect(publishesThroughLifecycle({ status: 'in_review' })).toBe(true)
		expect(publishesThroughLifecycle({ status: 'published' })).toBe(false)
		expect(publishesThroughLifecycle({ status: 'archived' })).toBe(false)
		expect(publishesThroughLifecycle({ title: 'No lifecycle' })).toBe(false)
		expect(publishesThroughLifecycle(null)).toBe(false)
	})

	it('publishes an approved publication with publish and a draft with publishWithoutReview', () => {
		expect(publishActionFor({ status: 'approved' })).toBe('publish')
		expect(publishActionFor({ status: 'draft' })).toBe('publishWithoutReview')
		expect(publishActionFor({})).toBe('publishWithoutReview')
	})

	it('posts the action and the publication date to OpenRegister\'s transition route', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'pub-1', status: 'published' } })

		const answer = await publishThroughLifecycle({ status: 'approved', '@self': { id: 'pub-1' } }, '2026-11-17')

		expect(answer).toEqual({ id: 'pub-1', status: 'published' })
		expect(post).toHaveBeenCalledTimes(1)
		const [url, body] = post.mock.calls[0]
		expect(url).toContain('/apps/openregister/api/objects/pub-1/transition')
		expect(body).toEqual({ action: 'publish', data: { publicationDate: '2026-11-17', depublicationDate: null } })
	})

	it('lets a refused move reject, so the dialog reports it and writes no date', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('422'))

		await expect(publishThroughLifecycle({ status: 'draft', id: 'pub-2' }, '2026-11-17')).rejects.toThrow('422')
	})

	it('is what the mass publish dialog calls before any date write', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/modals/object/MassPublishObjects.vue'), 'utf8')
		const transition = source.indexOf('await publishThroughLifecycle(obj, targetDate)')
		const save = source.indexOf('await objectStore.saveObject(clone')

		expect(transition).toBeGreaterThan(-1)
		expect(save).toBeGreaterThan(transition)
	})
})
