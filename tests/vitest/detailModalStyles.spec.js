/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The detail modals load their shared layout from src/css/detail-modal.css.
 * Each must still compile to the scoped rules it had when it carried its own
 * copy (the golden fixture). The directory modal also gets the link rules,
 * which match nothing there because its template has no links.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { scopedRules } from './helpers/scopedStyles.js'

const golden = JSON.parse(
	readFileSync(
		path.resolve(__dirname, 'fixtures/detail-modal-styles-golden.json'),
		'utf8',
	),
)

const extra = {
	'src/modals/directory/ViewDirectoryModal.vue': [
		'.detailItem a[data-v-golden] {color:var(--color-primary);text-decoration:none}',
		'.detailItem a[data-v-golden]:hover {text-decoration:underline}',
	],
}

describe('detail modal styles', () => {
	for (const file of Object.keys(golden)) {
		it(`${file} compiles to its recorded scoped rules`, () => {
			expect(scopedRules(file)).toEqual(
				[...golden[file], ...(extra[file] || [])].sort(),
			)
		})
	}

	it('the directory modal has no link the extra rules could style', () => {
		const source = readFileSync(
			path.resolve(
				__dirname,
				'../../src/modals/directory/ViewDirectoryModal.vue',
			),
			'utf8',
		)
		expect(source).not.toMatch(/<a[\s>]/)
	})
})
