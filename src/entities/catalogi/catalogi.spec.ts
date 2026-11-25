/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Test suite for Catalogi entity
 * @module Entities
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */

import { Catalogi } from './catalogi'
import { mockCatalogi, mockCatalogiList } from './catalogi.mock'

describe('Catalogi Store', () => {
	it('create Catalogi entity with full data', () => {
		const catalogi = new Catalogi(mockCatalogi)

		expect(catalogi).toBeInstanceOf(Catalogi)
		expect(catalogi).toEqual(mockCatalogi)

		expect(catalogi.validate().success).toBe(true)
	})

	it('create Catalogi entity with partial data', () => {
		const catalogi = new Catalogi(mockCatalogiList[1])

		expect(catalogi).toBeInstanceOf(Catalogi)
		expect(catalogi).toEqual(mockCatalogiList[1])

		expect(catalogi.validate().success).toBe(true)
	})

	it('create Catalogi entity with falsy data', () => {
		const catalogi = new Catalogi({
			id: '',
			title: '',
			summary: '',
			description: '',
			image: '',
			listed: false,
			organization: '',
			registers: [],
			schemas: [],
			filters: {},
			status: 'development',
			slug: '',
			hasWooSitemap: false,
			hasOoapi: false,
			hasDcat: false,
			dcatLicense: '',
			showWithheld: false,
		})

		expect(catalogi).toBeInstanceOf(Catalogi)
		expect(catalogi.validate().success).toBe(false)
	})

	// @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-6.2
	it('keeps the DCAT switch and its licence, so the catalogue form saves them (DCAT-010)', () => {
		const catalogi = new Catalogi({
			...mockCatalogi,
			hasDcat: true,
			dcatLicense: 'http://publications.europa.eu/resource/authority/licence/CC0',
		})

		expect(catalogi.hasDcat).toBe(true)
		expect(catalogi.dcatLicense).toBe('http://publications.europa.eu/resource/authority/licence/CC0')
		expect(new Catalogi({ ...mockCatalogi, hasDcat: 'yes' as unknown as boolean }).hasDcat).toBe(false)
	})
})
