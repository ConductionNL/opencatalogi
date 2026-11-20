/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure helpers of the admin settings page:
 * `settingsSchemas.js` (schema lists that hold ids or objects),
 * `settingsRequests.js` (publishing options, sync interval, directory sync)
 * and `remediationHints.js` (check reasons to hints).
 */

import { remediationHintFor } from './remediationHints.js'
import {
	directorySyncCounts,
	directorySyncFailed,
	directorySyncFailure,
	postSyncInterval,
	publishingOptionsFrom,
	publishingOptionsPayload,
	requestDirectorySync,
	syncIntervalSeconds,
} from './settingsRequests.js'
import {
	configuredSchema,
	hasSchemaObjects,
	isSchemaObject,
	schemaNamedLike,
	schemaOptionsOf,
} from './settingsSchemas.js'

const SCHEMAS = [
	{ id: 3, title: 'Publication' },
	{ id: 7, title: 'Catalog' },
	{ id: 9 },
	null,
]

describe('settingsSchemas', () => {
	it('counts only objects with an id and a title as schemas', () => {
		expect(isSchemaObject({ id: 3, title: 'Publication' })).toBe(true)
		expect(isSchemaObject({ id: 3 })).toBe(false)
		expect(isSchemaObject({ title: 'x' })).toBe(false)
		expect(isSchemaObject(null)).toBe(false)
		expect(isSchemaObject('3')).toBe(false)
		expect(hasSchemaObjects(SCHEMAS)).toBe(true)
		expect(hasSchemaObjects([{ id: 9 }, 4])).toBe(false)
		expect(hasSchemaObjects([])).toBe(false)
		expect(hasSchemaObjects(undefined)).toBe(false)
	})

	it('finds the configured schema among objects, else a fallback named after the id', () => {
		expect(configuredSchema(SCHEMAS, '7')).toEqual({ id: 7, title: 'Catalog' })
		expect(configuredSchema(SCHEMAS, '42')).toEqual({ id: '42', title: 'Schema 42' })
	})

	it('answers a listed id with a fallback, and an unlisted id with the same fallback', () => {
		expect(configuredSchema([3, 7], '7')).toEqual({ id: '7', title: 'Schema 7' })
		expect(configuredSchema([3, 7], '8')).toEqual({ id: '8', title: 'Schema 8' })
	})

	it('finds a schema by title, ignoring case and invalid entries', () => {
		expect(schemaNamedLike(SCHEMAS, 'publication')).toEqual({ id: 3, title: 'Publication' })
		expect(schemaNamedLike(SCHEMAS, 'CATALOG')).toEqual({ id: 7, title: 'Catalog' })
		expect(schemaNamedLike(SCHEMAS, 'theme')).toBeUndefined()
	})

	it('builds select options from objects, or from ids when the list holds numbers', () => {
		expect(schemaOptionsOf(SCHEMAS)).toEqual([
			{ label: 'Publication', value: '3' },
			{ label: 'Catalog', value: '7' },
		])
		expect(schemaOptionsOf([3, '5', 7])).toEqual([
			{ label: 'Schema 3', value: '3' },
			{ label: 'Schema 7', value: '7' },
		])
		expect(schemaOptionsOf([])).toEqual([])
	})
})

describe('settingsRequests', () => {
	afterEach(() => {
		delete global.fetch
	})

	it('maps the publishing options between the API and the form, both ways', () => {
		const api = { auto_publish_attachments: true, auto_publish_objects: false, use_old_style_publishing_view: true, other: 1 }
		const form = publishingOptionsFrom(api)

		// publication_review_required absent from the answer: review stays on.
		expect(form).toEqual({ autoPublishAttachments: true, autoPublishObjects: false, useOldStylePublishingView: true, publicationReviewRequired: true })
		expect(publishingOptionsPayload(form)).toEqual({
			auto_publish_attachments: true,
			auto_publish_objects: false,
			use_old_style_publishing_view: true,
			publication_review_required: true,
		})
	})

	it('carries the review setting both ways, and only false turns review off', () => {
		expect(publishingOptionsFrom({ publication_review_required: false }).publicationReviewRequired).toBe(false)
		expect(publishingOptionsFrom({ publication_review_required: true }).publicationReviewRequired).toBe(true)
		expect(publishingOptionsPayload({ publicationReviewRequired: false }).publication_review_required).toBe(false)
	})

	it('turns the sync interval into seconds, falling back to the default minutes', () => {
		expect(syncIntervalSeconds({ intervalMinutes: '15', defaultMinutes: 60 })).toBe(900)
		expect(syncIntervalSeconds({ intervalMinutes: 5, defaultMinutes: 60 })).toBe(300)
		expect(syncIntervalSeconds({ intervalMinutes: '', defaultMinutes: 60 })).toBe(3600)
		expect(syncIntervalSeconds({ intervalMinutes: 'often', defaultMinutes: 30 })).toBe(1800)
	})

	it('posts the sync interval as an OCS JSON request and answers its body', async () => {
		global.fetch = jest.fn(async () => ({ json: async () => ({ success: true }) }))

		await expect(postSyncInterval(900)).resolves.toEqual({ success: true })
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings/sync', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
			body: JSON.stringify({ sync_interval_seconds: 900 }),
		})
	})

	it('requests a directory sync and hands back both the response and its body', async () => {
		const response = { ok: true, statusText: 'OK', json: async () => ({ synced_directories: 2 }) }
		global.fetch = jest.fn(async () => response)

		await expect(requestDirectorySync()).resolves.toEqual({ response, data: { synced_directories: 2 } })
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/listings/sync', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
		})
	})

	it('treats a non-ok response or an error in the body as a failed sync, and names why', () => {
		expect(directorySyncFailed({ ok: false }, {})).toBe(true)
		expect(directorySyncFailed({ ok: true }, { error: 'boom' })).toBe(true)
		expect(directorySyncFailed({ ok: true }, {})).toBe(false)

		expect(directorySyncFailure({ statusText: 'Bad Gateway' }, { message: 'Directory down', error: 'x' })).toBe('Directory down')
		expect(directorySyncFailure({ statusText: 'Bad Gateway' }, { error: 'x' })).toBe('x')
		expect(directorySyncFailure({ statusText: 'Bad Gateway' }, {})).toBe('Bad Gateway')
	})

	it('counts synced and failed directories, deriving the total when it is missing', () => {
		expect(directorySyncCounts({ synced_directories: 3, failed_directories: 1, total_directories: 5 })).toEqual({ synced: 3, total: 5, failed: 1 })
		expect(directorySyncCounts({ synced_directories: 3, failed_directories: 1 })).toEqual({ synced: 3, total: 4, failed: 1 })
		expect(directorySyncCounts({})).toEqual({ synced: 0, total: 0, failed: 0 })
	})
})

describe('remediationHints', () => {
	const t = jest.fn((app, text) => `[${app}] ${text}`)

	it('translates the hint of a known reason in the app\'s domain', () => {
		expect(remediationHintFor('http-404', t)).toBe('[opencatalogi] Not found (404) — check webserver routing/rewrites for this URL.')
		expect(remediationHintFor('url-mismatch', t)).toBe('[opencatalogi] The registered URL does not match this instance\'s public base URL.')
	})

	it('answers an unknown reason as itself, and never an inherited property', () => {
		expect(remediationHintFor('something-new', t)).toBe('something-new')
		expect(remediationHintFor('toString', t)).toBe('toString')
		expect(remediationHintFor('constructor', t)).toBe('constructor')
	})
})
