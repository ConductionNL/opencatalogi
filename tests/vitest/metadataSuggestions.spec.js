/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for src/services/metadataSuggestions.js: which suggestions wait for a
 * person, the endpoints the widget calls, who made a suggestion, the words for
 * a refusal, and the fields a rule filled (woo-metadata-suggestions).
 */
import { describe, expect, it } from 'vitest'
import {
	decisionPath,
	fieldLabels,
	filledByRule,
	pendingSuggestions,
	refusalMessage,
	sourceLabel,
	suggestionsPath,
} from '../../src/services/metadataSuggestions.js'

const t = (app, text) => `[${app}] ${text}`

describe('pendingSuggestions', () => {
	it('keeps only the pending ones, in order', () => {
		const list = [
			{ id: 'a', status: 'pending' },
			{ id: 'b', status: 'accepted' },
			null,
			{ id: 'c', status: 'rejected' },
			{ id: 'd', status: 'pending' },
		]
		expect(pendingSuggestions(list).map((s) => s.id)).toEqual(['a', 'd'])
	})

	it('reads anything but a list as none', () => {
		expect(pendingSuggestions(undefined)).toEqual([])
		expect(pendingSuggestions({ results: [] })).toEqual([])
	})
})

describe('the endpoints', () => {
	it('lists and asks on the publication, encoded', () => {
		expect(suggestionsPath('pub 1/x')).toBe(
			'/apps/opencatalogi/api/publications/pub%201%2Fx/metadata-suggestions',
		)
	})

	it('accepts or rejects one suggestion, and nothing else', () => {
		expect(decisionPath('s-1', 'accept')).toBe(
			'/apps/opencatalogi/api/metadata-suggestions/s-1/accept',
		)
		expect(decisionPath('s/2', 'reject')).toBe(
			'/apps/opencatalogi/api/metadata-suggestions/s%2F2/reject',
		)
		expect(() => decisionPath('s-1', 'delete')).toThrow('accepted or rejected')
	})
})

describe('labels and messages', () => {
	it('names who made a suggestion', () => {
		expect(sourceLabel('hermiq', t)).toBe('[opencatalogi] Suggested by Hermiq')
		expect(sourceLabel('rules', t)).toBe('[opencatalogi] Suggested by the rules')
	})

	it('labels every field a suggestion can fill', () => {
		expect(Object.keys(fieldLabels(t))).toEqual([
			'wooCategory',
			'soortHandeling',
			'organization',
			'title',
			'summary',
		])
	})

	it('uses the server message for a refusal, else a generic one', () => {
		expect(
			refusalMessage(
				{
					response: {
						data: { message: 'You may not change this publication.' },
					},
				},
				t,
			),
		).toBe('You may not change this publication.')
		expect(refusalMessage({ response: { data: { error: 'x' } } }, t)).toBe(
			'[opencatalogi] That did not work. Try again.',
		)
		expect(refusalMessage(new Error('network'), t)).toBe(
			'[opencatalogi] That did not work. Try again.',
		)
	})
})

describe('filledByRule', () => {
	it('lists the known fields a rule filled, with their labels', () => {
		const publication = {
			filledByRule: [
				{
					field: 'organization',
					value: 'org-a',
					rule: 'officer-member-of-one-organisation',
				},
				{ field: 'unknown', value: 'x' },
				{ field: 'wooCategory', value: 'infocat001' },
			],
		}
		expect(filledByRule(publication, t)).toEqual([
			{
				field: 'organization',
				label: '[opencatalogi] Organisation',
				value: 'org-a',
			},
			{
				field: 'wooCategory',
				label: '[opencatalogi] Woo information category',
				value: 'infocat001',
			},
		])
	})

	it('reads a publication without records as none filled', () => {
		expect(filledByRule(null, t)).toEqual([])
		expect(filledByRule({ filledByRule: 'x' }, t)).toEqual([])
	})
})
