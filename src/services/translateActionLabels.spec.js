/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Publications index "File list" row action (opencatalogi#1583) and the
 * label translation it depends on: CnRowActions renders a manifest action's
 * label as-is, so main.js translates it before the manifest reaches the
 * library.
 */

import enCatalogue from '../../l10n/en.json'
import nlCatalogue from '../../l10n/nl.json'
import manifest from '../manifest.json'
import { translateActionLabels } from './translateActionLabels.js'

const page = (id) => manifest.pages.find((p) => p.id === id)

// What the action does when clicked is covered by
// tests/vitest/publicationFileListAction.spec.js, through the library's own
// dispatch.
describe('Publications "File list" row action', () => {
	it('declares exactly one file-list action, with the list icon', () => {
		const fileList = page('Publications').config.actions.filter(
			(a) => a.id === 'file-list',
		)

		expect(fileList).toHaveLength(1)
		expect(fileList[0].icon).toBe('FormatListBulleted')
	})

	it('places File list between the built-in Edit and Copy', () => {
		expect(page('Publications').config.actions).toEqual([
			'builtin:view',
			'builtin:edit',
			expect.objectContaining({ id: 'file-list' }),
			'builtin:copy',
			'builtin:delete',
		])
	})

	it('keeps the built-in view, edit, copy and delete actions enabled', () => {
		const toggles = page('Publications').config.actionToggles

		// View is on by default; only an explicit false would turn it off.
		expect(toggles.showViewAction).not.toBe(false)
		expect(toggles.showEditAction).toBe(true)
		expect(toggles.showCopyAction).toBe(true)
		expect(toggles.showDeleteAction).toBe(true)
	})

	it('ships the label in the en and nl catalogues', () => {
		expect(enCatalogue.translations['File list']).toBe('File list')
		expect(nlCatalogue.translations['File list']).toBe('Bestandenlijst')
	})
})

describe('PublicationDetail Attachments widget', () => {
	// CnFilesCard requests `limit=maxDisplay` (default 5), so the widget shows
	// only as many files as this allows.
	it('lists up to 100 files', () => {
		const files = page('PublicationDetail').config.widgets.find(
			(w) => w.id === 'pub-files',
		)

		expect(files.integrationId).toBe('files')
		expect(files.props).toEqual({ maxDisplay: 100 })
	})
})

describe('translateActionLabels', () => {
	const input = {
		version: '1',
		pages: [
			{
				id: 'Index',
				config: {
					register: 'r',
					actions: [
						{ id: 'a', label: 'File list', route: 'Detail' },
						{ id: 'b' },
					],
				},
			},
			{ id: 'NoActions', config: { register: 'r' } },
			{ id: 'NoConfig' },
		],
	}

	it('translates every action label and leaves the rest of the manifest as it was', () => {
		const out = translateActionLabels(input, (key) => `nl:${key}`)

		expect(out.version).toBe('1')
		expect(out.pages[0].config.register).toBe('r')
		expect(out.pages[0].config.actions).toEqual([
			{ id: 'a', label: 'nl:File list', route: 'Detail' },
			{ id: 'b' },
		])
		expect(out.pages[1]).toBe(input.pages[1])
		expect(out.pages[2]).toBe(input.pages[2])
	})

	it('passes builtin placeholder strings through and still translates the objects beside them', () => {
		const out = translateActionLabels(
			{
				pages: [
					{
						id: 'Index',
						config: {
							actions: [
								'builtin:edit',
								{ id: 'a', label: 'File list' },
								'builtin:delete',
							],
						},
					},
				],
			},
			(key) => `nl:${key}`,
		)

		expect(out.pages[0].config.actions).toEqual([
			'builtin:edit',
			{ id: 'a', label: 'nl:File list' },
			'builtin:delete',
		])
	})

	it('does not mutate its input', () => {
		const snapshot = JSON.parse(JSON.stringify(input))

		translateActionLabels(input, (key) => `nl:${key}`)

		expect(input).toEqual(snapshot)
	})

	it('translates the real manifest with the nl catalogue', () => {
		const out = translateActionLabels(
			manifest,
			(key) => nlCatalogue.translations[key] ?? key,
		)
		const fileList = (pages) =>
			pages
				.find((p) => p.id === 'Publications')
				.config.actions.find((a) => a.id === 'file-list')

		expect(fileList(out.pages).label).toBe('Bestandenlijst')
		expect(fileList(manifest.pages).label).toBe('File list')
	})
})
