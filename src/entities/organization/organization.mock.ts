/**
 * Organization mock data for testing
 *
 * @module Entities
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */

import type { TOrganization } from './organization.types'

import { Organization } from './organization'

/** @typedef {import('./organization.types').TOrganization} TOrganization */
/** @typedef {import('./organization').Organization} Organization */

/**
 * Mock organization data for testing
 *
 * @return Array of mock organization data
 */
export function mockOrganizationData(): TOrganization[] {
	return [
		{
			id: '1',
			name: 'Test Organization',
			summary: 'A test organization',
			description: 'This is a test organization for development purposes',
			// These must satisfy the real NL identifier formats the entity's zod
			// schema enforces — the previous filler value ('00001234567890123456'
			// in all four) matched none of them, so `validate().success` was false
			// and the "full data" spec could never pass.
			oin: '00000001234567890000', // 0000000 + 10 digits + 000
			tooi: 'gm0363', // \w{2,} + 4 digits
			rsin: '123456789', // exactly 9 digits
			pki: '12345', // digits
			image: 'https://example.com/image.jpg',
		},
		// [1] PARTIAL — only the two fields the schema requires are filled; every
		// optional identifier is '' (which the schema allows via `.or(z.literal(''))`).
		// `summary` was '' here before, which the schema rejects with `min(1)`, so the
		// "partial data" spec asserted validate().success === true against data that
		// could never validate.
		{
			id: '2',
			name: 'Minimal Organization',
			summary: 'A minimal test organization',
			description: '',
			oin: '',
			tooi: '',
			rsin: '',
			pki: '',
			image: '',
		},
		// [2] FALSY — every field empty. The specs index mockOrganizations()[2] for
		// the "falsy data" case, but only two entries existed, so the entity was
		// constructed from `undefined` and compared against `undefined`.
		{
			id: '',
			name: '',
			summary: '',
			description: '',
			oin: '',
			tooi: '',
			rsin: '',
			pki: '',
			image: '',
		},
	]
}

/**
 * Creates Organization instances from mock data
 *
 * @param data Optional mock data to use instead of default
 * @return Array of Organization instances
 */
export function mockOrganizations(
	data: TOrganization[] = mockOrganizationData(),
): Organization[] {
	return data.map((item) => new Organization(item))
}
