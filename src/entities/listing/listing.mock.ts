/**
 * Listing mock data for testing
 *
 * @module Entities
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */

import type { TListing } from './listing.types'

import { Listing } from './listing'

/** @typedef {import('./listing.types').TListing} TListing */
/** @typedef {import('./listing').Listing} Listing */

/**
 * Mock listing data for testing purposes
 *
 * @return Array of mock listing data
 */
export function mockListingData(): TListing[] {
	return [
		{
			id: '1',
			catalogusId: '24',
			title: 'Test Listing',
			summary: 'A test listing',
			description: 'This is a test listing for testing purposes',
			search: 'https://example.com/search',
			publications: 'https://example.com/publications',
			directory: 'https://example.com/directory',
			metadata: ['test', 'metadata'],
			status: 'active',
			statusCode: 200,
			// Fixed, not `new Date()`: the spec builds the expected object and the
			// entity from separate calls to this factory, so a live clock made the
			// two timestamps differ by a millisecond or two and the deep-equality
			// assertion failed at random.
			lastSync: '2026-01-01T00:00:00.000Z',
			available: true,
			default: true,
			organization: '1',
			publicationTypes: [],
		},
		{
			id: '2',
			catalogusId: '24',
			title: 'Minimal Listing',
			summary: 'A minimal test listing',
			description: '',
			search: '',
			publications: '',
			directory: '',
			metadata: [],
			status: 'inactive',
			statusCode: 200,
			lastSync: '',
			available: false,
			default: false,
			organization: '1',
			publicationTypes: [],
		},
		// [2] FALSY — every field empty/zero. The specs index mockListings()[2] for
		// the "falsy data" case, but only two entries existed, so the entity was
		// built from `undefined` and compared against `undefined`.
		//
		// `available` is `true` here on purpose: Listing.hydrate() does
		// `data?.available || true`, so a falsy value can never survive — the entity
		// always reports `true`. Setting it to `false` would make the round-trip
		// assertion fail on a quirk of the entity rather than on the fixture. That
		// `|| true` looks like a real defect (it makes `available: false`
		// unrepresentable), but fixing it changes runtime behaviour and belongs in
		// its own change, not here.
		{
			id: '',
			catalogusId: '',
			title: '',
			summary: '',
			description: '',
			search: '',
			publications: '',
			directory: '',
			metadata: [],
			status: '',
			statusCode: 0,
			lastSync: '',
			available: true,
			default: false,
			organization: '',
			publicationTypes: [],
		},
	]
}

/**
 * Creates Listing instances from mock data
 *
 * @param data Optional mock data to use instead of default
 * @return Array of Listing instances
 */
export function mockListings(data: TListing[] = mockListingData()): Listing[] {
	return data.map((item) => new Listing(item))
}
