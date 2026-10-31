/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Objects of a schema other than the publication schema in a catalog, such as
 * harvested publiccode components: the Publications page's default columns for
 * them, the generic detail page they open on, and the publiccode schema's
 * description, which only reaches the form and Data card through a `widget`.
 */

import {
	columnsFromSchema,
	fieldsFromSchema,
} from '@conduction/nextcloud-vue/src/utils/schema.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const SETTINGS = path.join(ROOT, 'lib/Settings')

// Metadata keys OpenRegister's MagicSearchHandler::applySorting maps to a column.
const SORTABLE_METADATA = /^@self\.(created|updated|name|description|summary|uuid)$/

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))

const pages = readJson(path.join(ROOT, 'src/manifest.json')).pages
const publications = pages.find((p) => p.id === 'Publications')
const detail = pages.find((p) => p.id === 'CatalogObjectDetail')

const fragment = readJson(path.join(SETTINGS, 'register.d/publiccode-software.json'))
const publiccode = fragment.components.schemas.publiccode

describe('Publications page, for a catalog without the publication schema', () => {
	const columns = publications.config.otherPairConfig.columns

	it('lists name, summary and updated instead of every schema property', () => {
		expect(columns.map((col) => col.key)).toEqual([
			'@self.name',
			'@self.summary',
			'@self.updated',
		])
	})

	it('only sorts on keys OpenRegister can order by', () => {
		for (const col of columns.filter((c) => c.sortable)) {
			expect(col.key).toMatch(SORTABLE_METADATA)
		}
	})

	it('shows the filter and columns sidebar', () => {
		expect(publications.config.sidebar).toMatchObject({
			enabled: true,
			showMetadata: true,
		})
	})
})

describe('CatalogObjectDetail page', () => {
	it('takes the register, schema and id from its route', () => {
		for (const param of ['catalogSlug', 'register', 'schema', 'id']) {
			expect(detail.route).toContain(`:${param}`)
		}
		expect(detail.type).toBe('detail')
		expect(detail.config).toMatchObject({
			register: '@route.register',
			schema: '@route.schema',
			idParam: '@route.id',
		})
	})

	it('neither shadows nor is shadowed by another /publications route', () => {
		const segments = (route) => route.split('/').filter(Boolean)
		// Two routes collide when every segment pair is equal or either is a param.
		const collides = (a, b) =>
			a.length === b.length
			&& a.every(
				(part, i) =>
					part === b[i] || part.startsWith(':') || b[i].startsWith(':'),
			)
		const others = pages.filter(
			(p) => p.id !== detail.id && p.route?.startsWith('/publications'),
		)
		expect(others.length).toBeGreaterThan(0)
		for (const other of others) {
			expect(
				collides(segments(detail.route), segments(other.route)),
				other.id,
			).toBe(false)
		}
	})

	it('keeps its Data card read-only, so a free-form object is never overwritten inline', () => {
		const data = detail.config.widgets.filter((w) => w.type === 'data')
		expect(data.length).toBeGreaterThan(0)
		for (const widget of data) {
			expect(widget.content.editable).toBe(false)
		}
	})

	/**
	 * File attachment in OpenRegister is resolved by object id alone
	 * (FileMapper::getFilesForObject()), never by a schema-declared property,
	 * so it applies to every row this page opens regardless of schema — the
	 * same generic `integration`/`files` widget PublicationDetail uses.
	 */
	it('shows an Attachments widget, the same generic integration PublicationDetail uses', () => {
		const files = detail.config.widgets.filter((w) => w.type === 'integration' && w.integrationId === 'files')
		expect(files.length).toBe(1)

		const publicationDetail = pages.find((p) => p.id === 'PublicationDetail')
		const pubFiles = publicationDetail.config.widgets.find((w) => w.id === 'pub-files')
		expect(files[0].type).toBe(pubFiles.type)
		expect(files[0].integrationId).toBe(pubFiles.integrationId)
	})

	it('lays every widget out exactly once', () => {
		const ids = detail.config.widgets.map((w) => w.id).sort()
		const laidOut = detail.config.layout.map((cell) => cell.widgetId).sort()
		expect(laidOut).toEqual(ids)
	})
})

describe('publiccode schema', () => {
	it('shows the per-language description in the form and the Data card', () => {
		const keys = fieldsFromSchema(publiccode).map((field) => field.key)
		expect(keys).toContain('description')
		const description = fieldsFromSchema(publiccode).find(
			(f) => f.key === 'description',
		)
		expect(description.widget).toBe('json')
	})

	it('keeps the raw publiccode.yml dump hidden', () => {
		const keys = fieldsFromSchema(publiccode).map((field) => field.key)
		expect(keys).not.toContain('rawPubliccode')
	})

	it('fills the summary and description metadata from the Dutch, then the English description', () => {
		const chain = (value) => value.split('|').map((part) => part.trim())
		expect(chain(publiccode.configuration.objectSummaryField)).toEqual([
			'description.nl.shortDescription',
			'description.en.shortDescription',
		])
		expect(chain(publiccode.configuration.objectDescriptionField)).toEqual([
			'description.nl.longDescription',
			'description.en.longDescription',
		])
	})

	it('still lists scalar properties as optional columns', () => {
		const keys = columnsFromSchema(publiccode).map((col) => col.key)
		expect(keys).toContain('softwareType')
	})
})
