/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/federationResultTarget.js: what a click on a
 * federation search result does. The fixture holds the effects the click
 * handler of FederationSearch.vue produced (window.open, router push, catalog
 * collection reads) for each result shape. Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	catalogSlugFromTitle,
	peerOrigin,
	resultCatalogSlug,
	resultClickTarget,
} from '../../src/services/federationResultTarget.js'
import golden from './fixtures/federation-result-click-golden.json'

/**
 * The effects of one click, produced the way FederationSearch.onResultClick
 * applies a target.
 *
 * @param {object} testCase A golden case.
 * @return {Array} The recorded effects.
 */
function clickEffects(testCase) {
	const effects = []
	const getCatalogs = () => {
		effects.push(['getCollection', 'catalog'])
		return golden.catalogs[testCase.catalogs]
	}
	const target = resultClickTarget(testCase.result, getCatalogs)
	if (target?.open) {
		effects.push(['open', target.open, '_blank', 'noopener,noreferrer'])
	} else if (target?.route) {
		effects.push(['push', target.route])
	}
	return effects
}

describe('resultClickTarget (golden)', () => {
	it.each(golden.cases.map((c) => [c.name, c]))('%s', (_name, testCase) => {
		expect(clickEffects(testCase)).toEqual(testCase.effects)
	})
})

describe('federationResultTarget helpers', () => {
	it('slugifies a title the OpenCatalogi way', () => {
		expect(catalogSlugFromTitle('  Hello, World -- Two  ')).toBe(
			'hello-world-two',
		)
		expect(catalogSlugFromTitle('---')).toBe('')
	})

	it('reads the catalog collection only for a stub with an id and no slug', () => {
		let reads = 0
		const getCatalogs = () => {
			reads++
			return []
		}
		resultCatalogSlug({ '@self': { catalogs: [{ slug: 's' }] } }, getCatalogs)
		resultCatalogSlug({ '@self': { catalogs: [{ title: 'T' }] } }, getCatalogs)
		expect(reads).toBe(0)
		resultCatalogSlug({ '@self': { catalogs: [{ id: 'c' }] } }, getCatalogs)
		expect(reads).toBe(1)
	})

	it('prefers the origin of @self.uri over the directory host', () => {
		expect(
			peerOrigin({
				'@self': { uri: 'http://localhost:9082/a/b', directory: 'nc-fed-2' },
			}),
		).toBe('http://localhost:9082')
		expect(peerOrigin({ '@self': { directory: 'http://' } })).toBeNull()
	})
})
