/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DocsAccessibilityReportTest: the published screen reader report
 * (REQ-OFA-003, scenario "The report is published with its date"). It carries
 * a date, the virtual pass, a section per real screen reader for the officer
 * and the citizen flow, and no section claims a pass without who ran it and
 * when. A pass nobody ran says "not yet run".
 *
 * @spec openspec/changes/officer-flow-accessibility/specs/officer-accessibility/spec.md#requirement-a-screen-reader-pass-is-run-and-its-result-published-req-ofa-003
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const REPORT = path.resolve(__dirname, '../../docs/accessibility/screen-reader-report.md')
const READERS = ['NVDA with Firefox on Windows', 'VoiceOver with Safari on macOS']
const FLOWS = ['Officer flow', 'Citizen flow']
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

/**
 * The report's lines.
 *
 * @return {string[]} The lines.
 */
function lines() {
	return fs.readFileSync(REPORT, 'utf8').split('\n')
}

/**
 * The value of a `Key: value` line inside a block of lines, or null when absent.
 *
 * @param {string[]} block The lines.
 * @param {string} key The key.
 * @return {?string} The trimmed value.
 */
function field(block, key) {
	const line = block.find((l) => l.startsWith(key + ':'))
	return line === undefined ? null : line.slice(key.length + 1).trim()
}

/**
 * The lines under a heading, up to the next heading of the same or a higher level.
 *
 * @param {string[]} all The report's lines.
 * @param {string} heading The heading line, with its hashes.
 * @return {?string[]} The section, or null when the heading is missing.
 */
export function section(all, heading) {
	const start = all.indexOf(heading)
	if (start === -1) {
		return null
	}
	const level = heading.indexOf(' ')
	const end = all.findIndex((l, i) => i > start && /^#+ /.test(l) && l.indexOf(' ') <= level)
	return all.slice(start + 1, end === -1 ? undefined : end)
}

/**
 * Why a pass section is not acceptable, or null when it is.
 *
 * @param {string[]} block The section's lines.
 * @return {?string} The problem.
 */
export function passProblem(block) {
	const status = field(block, 'Status')
	if (status === null || status === '') {
		return 'no status'
	}
	if (status === 'not yet run') {
		return null
	}
	if (!['passed', 'failed'].includes(status)) {
		return 'status "' + status + '" is not passed, failed or not yet run'
	}
	if (!field(block, 'Run by')) {
		return 'a ' + status + ' pass without who ran it'
	}
	if (!ISO_DATE.test(field(block, 'Date') ?? '')) {
		return 'a ' + status + ' pass without a date'
	}
	return null
}

describe('DocsAccessibilityReportTest', () => {
	it('carries the date of the report', () => {
		const date = lines().find((l) => l.startsWith('Report date:'))
		expect(date).toBeDefined()
		expect(date.slice('Report date:'.length).trim()).toMatch(ISO_DATE)
	})

	it('has the virtual pass and both flows for both screen readers', () => {
		const all = lines()
		expect(section(all, '## Virtual screen reader')).not.toBeNull()
		for (const reader of READERS) {
			const readerSection = section(all, '## ' + reader)
			expect(readerSection, reader).not.toBeNull()
			for (const flow of FLOWS) {
				expect(section(readerSection, '### ' + flow), reader + ', ' + flow).not.toBeNull()
			}
		}
	})

	it('claims no pass without who ran it and when', () => {
		const all = lines()
		const problems = []
		const virtual = section(all, '## Virtual screen reader')
		const virtualStatus = field(virtual, 'Status')
		if (virtualStatus !== 'not yet run' && virtualStatus !== 'run') {
			problems.push('virtual: status "' + virtualStatus + '"')
		}
		for (const reader of READERS) {
			for (const flow of FLOWS) {
				const problem = passProblem(section(section(all, '## ' + reader), '### ' + flow))
				if (problem !== null) {
					problems.push(reader + ', ' + flow + ': ' + problem)
				}
			}
		}
		expect(problems).toEqual([])
	})

	it('refuses a pass without a name or a date', () => {
		expect(passProblem(['Status: passed', 'Run by:', 'Date: 2026-11-13'])).toBe('a passed pass without who ran it')
		expect(passProblem(['Status: failed', 'Run by: A. Tester', 'Date:'])).toBe('a failed pass without a date')
		expect(passProblem(['Status: passed', 'Run by: A. Tester', 'Date: 13-11-2026'])).toBe('a passed pass without a date')
		expect(passProblem(['Status: done'])).toBe('status "done" is not passed, failed or not yet run')
		expect(passProblem(['Run by: A. Tester'])).toBe('no status')
		expect(passProblem(['Status: passed', 'Run by: A. Tester', 'Date: 2026-11-13'])).toBeNull()
		expect(passProblem(['Status: not yet run', 'Run by:', 'Date:'])).toBeNull()
	})

	it('has no em dash', () => {
		expect(fs.readFileSync(REPORT, 'utf8').includes('—')).toBe(false)
	})
})
