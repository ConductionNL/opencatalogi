/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */
import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchPublicationQuality, missingFieldNames } from '../../src/services/publicationQuality.js'

const t = (app, text) => `~${text}`

afterEach(() => {
	vi.restoreAllMocks()
})

describe('missingFieldNames', () => {
	it('names each missing property as the field the officer knows, once', () => {
		expect(missingFieldNames(['dct:license', 'dcat:theme', 'dct:format', 'dcat:mediaType', 'ex:other'], t)).toEqual([
			'~License', '~Theme', '~File format', 'ex:other',
		])
	})

	it('gives nothing for an answer without a list', () => {
		expect(missingFieldNames(undefined, t)).toEqual([])
	})
})

describe('fetchPublicationQuality', () => {
	it('asks the score of the publication in the catalogue it is opened in', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { total: 40, dimensions: {}, missing: ['dct:license'] } })

		await expect(fetchPublicationQuality('w o', 'p/1')).resolves.toEqual({ total: 40, missing: ['dct:license'] })
		expect(get).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/catalogs/w%20o/publications/p%2F1/quality')
	})

	it('reads a malformed answer as a zero score with nothing missing', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { total: 'x' } })

		await expect(fetchPublicationQuality('woo', 'p-1')).resolves.toEqual({ total: 0, missing: [] })
	})
})
