/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/instanceBanners.js: the banners a signed-in
 * user sees and how a dismissal is sent (REQ-PCS-103). The server decides the
 * period and remembers the dismissal per user (NoticeBoardController::banners
 * and ::dismissBanner); these tests pin the client half against those routes.
 */
import { describe, expect, it } from 'vitest'
import { dismissInstanceBanner, loadInstanceBanners, noteTypeFor } from '../../src/services/instanceBanners.js'

/**
 * A fake HTTP client that records calls and answers from a map.
 *
 * @param {object} answers Responses by method.
 * @return {object} The client.
 */
function fakeHttp(answers = {}) {
	const calls = []
	return {
		calls,
		async get(url) {
			calls.push({ method: 'get', url })
			if (answers.get instanceof Error) throw answers.get
			return { data: answers.get }
		},
		async post(url, body) {
			calls.push({ method: 'post', url, body })
			return { data: answers.post ?? { dismissed: true } }
		},
	}
}

const url = (path) => '/index.php/apps/opencatalogi' + path

describe('loadInstanceBanners (REQ-PCS-103)', () => {
	it('reads the banners route and keeps the banners the server returns', async () => {
		const http = fakeHttp({
			get: { banners: [{ id: 'b1', body: 'Onderhoud vanavond', severity: 'warning', dismissable: true }] },
		})
		const banners = await loadInstanceBanners(http, url)
		expect(http.calls[0]).toEqual({ method: 'get', url: '/index.php/apps/opencatalogi/api/banners' })
		expect(banners).toEqual([{ id: 'b1', body: 'Onderhoud vanavond', severity: 'warning', dismissable: true }])
	})

	it('treats a missing dismissable flag as dismissable, like the server does', async () => {
		const http = fakeHttp({ get: { banners: [{ id: 'b2', body: 'Nieuw', severity: 'info' }] } })
		const [banner] = await loadInstanceBanners(http, url)
		expect(banner.dismissable).toBe(true)
	})

	it('drops a banner without an id or a body', async () => {
		const http = fakeHttp({ get: { banners: [{ body: 'no id' }, { id: 'x', body: '' }, null] } })
		expect(await loadInstanceBanners(http, url)).toEqual([])
	})

	it('shows nothing when the banners cannot be read', async () => {
		const http = fakeHttp({ get: new Error('503') })
		expect(await loadInstanceBanners(http, url)).toEqual([])
	})
})

describe('dismissInstanceBanner (REQ-PCS-103)', () => {
	it('posts the banner id to the dismiss route', async () => {
		const http = fakeHttp()
		await dismissInstanceBanner(http, url, 'b1')
		expect(http.calls[0]).toEqual({
			method: 'post',
			url: '/index.php/apps/opencatalogi/api/banners/dismiss',
			body: { banner: 'b1' },
		})
	})
})

describe('noteTypeFor', () => {
	it('maps the three severities onto note card types', () => {
		expect(noteTypeFor('info')).toBe('info')
		expect(noteTypeFor('warning')).toBe('warning')
		expect(noteTypeFor('critical')).toBe('error')
		expect(noteTypeFor('unknown')).toBe('info')
	})
})
