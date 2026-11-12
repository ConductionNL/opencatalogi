/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { PublicationType } from './publicationType'
import { mockPublicationType } from './publicationType.mock'

describe('PublicationType entity', () => {
	it('create PublicationType entity with full data', () => {
		const publicationType = new PublicationType(mockPublicationType()[0])

		expect(publicationType).toBeInstanceOf(PublicationType)
		expect(publicationType).toEqual(mockPublicationType()[0])

		expect(publicationType.validate().success).toBe(true)
	})

	it('create PublicationType entity with partial data', () => {
		const publicationType = new PublicationType(mockPublicationType()[1])

		expect(publicationType).toBeInstanceOf(PublicationType)
		expect(publicationType).toEqual(mockPublicationType()[1])

		expect(publicationType.validate().success).toBe(true)
	})

	it('create PublicationType entity with falsy data', () => {
		const publicationType = new PublicationType(mockPublicationType()[2])

		expect(publicationType).toBeInstanceOf(PublicationType)
		expect(publicationType).toEqual(mockPublicationType()[2])

		expect(publicationType.validate().success).toBe(false)
	})
})
