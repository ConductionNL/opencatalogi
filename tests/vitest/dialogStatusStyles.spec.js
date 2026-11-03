/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The confirm dialogs load their shared status rules from
 * src/css/dialog-status.css. Each must still compile to the global rules it
 * had when it carried its own copy (the golden fixture).
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { scopedRules } from './helpers/scopedStyles.js'

const golden = JSON.parse(
	readFileSync(
		path.resolve(__dirname, 'fixtures/dialog-status-styles-golden.json'),
		'utf8',
	),
)

describe('dialog status styles', () => {
	for (const file of Object.keys(golden)) {
		it(`${file} compiles to its recorded rules`, () => {
			expect(scopedRules(file)).toEqual(golden[file])
		})
	}
})
