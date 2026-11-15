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
	sourceUrl,
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

describe('sourceUrl', () => {
	const remote = {
		id: 'p/1',
		'@self': { directory: 'nc-fed-2', uri: 'http://localhost:9082/apps/openregister/api/objects/a' },
	}

	it('links to the source app\'s history-mode publication route, never a hash route', () => {
		const url = sourceUrl(remote, 'besluiten')
		expect(url).toBe('http://localhost:9082/index.php/apps/opencatalogi/publications/besluiten/p%2F1')
		expect(url).not.toContain('#')
	})

	it('falls back to the source app root without a slug or an id', () => {
		expect(sourceUrl(remote, null)).toBe('http://localhost:9082/index.php/apps/opencatalogi/')
		expect(sourceUrl({ '@self': { directory: 'peer.example' } }, 'besluiten')).toBe('https://peer.example/index.php/apps/opencatalogi/')
	})

	it('takes the id from @self when the publication has none, and gives null without a host', () => {
		expect(sourceUrl({ '@self': { id: 'sid', directory: 'peer.example' } }, 's')).toBe('https://peer.example/index.php/apps/opencatalogi/publications/s/sid')
		expect(sourceUrl({ id: 'p', '@self': { directory: 'http://' } }, 's')).toBeNull()
	})
})

describe('a federated result stays in the app', () => {
	it('never asks to open a new tab', () => {
		for (const testCase of golden.cases) {
			const target = resultClickTarget(testCase.result, () => golden.catalogs[testCase.catalogs])
			expect(target?.open).toBeUndefined()
		}
	})
})
