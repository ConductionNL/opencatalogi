/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/harvestRunLabel.js: the sentence the
 * publiccode harvest settings show about the last run. The fixture holds what
 * PubliccodeHarvest's lastRunLabel computed returned for each run shape, with
 * dates rendered as D[<ISO>] so the output does not depend on the locale.
 * Offline, no DOM.
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { lastRunLabel } from '../../src/services/harvestRunLabel.js'
import golden from './fixtures/harvest-last-run-label-golden.json'

const toLocaleString = Date.prototype.toLocaleString

beforeAll(() => {
	Date.prototype.toLocaleString = function () {
		return 'D[' + this.toISOString() + ']'
	}
})

afterAll(() => {
	Date.prototype.toLocaleString = toLocaleString
})

describe('lastRunLabel (golden)', () => {
	it.each(golden.map((c) => [c.name, c]))('%s', (_name, testCase) => {
		const run = testCase.run === '(absent)' ? undefined : testCase.run
		expect(lastRunLabel(run)).toBe(testCase.label)
	})
})
