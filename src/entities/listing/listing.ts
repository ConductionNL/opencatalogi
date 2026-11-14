/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Listing entity class
 *
 * @module Entities
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */

/* eslint-disable @typescript-eslint/no-explicit-any */
import type { ZodSafeParseResult } from 'zod'
import type { TOrganization } from '../organization'
import type { TListing } from './listing.types'

import { z } from 'zod'
import { fillDefaults } from '../fillDefaults.js'

/** @typedef {import('./listing.types').TListing} TListing */
/** @typedef {import('zod').ZodSafeParseResult<unknown>} ZodSafeParseResult */

/**
 * Every listing field but the id, with the value it takes when the data
 * leaves it out or sends a falsy one.
 */
const LISTING_DEFAULTS = {
	catalogusId: '',
	title: '',
	summary: '',
	description: '',
	search: '',
	publications: '',
	directory: '',
	metadata: [] as string[],
	status: '',
	statusCode: 0,
	lastSync: '' as string | Date,
	// a falsy value still counts as available
	available: true,
	default: false,
	organization: '' as string | TOrganization,
	publicationTypes: [] as any[],
}

/**
 * Listing class representing a catalog listing in the system
 *
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 */
export class Listing implements TListing {
	public id!: string
	public catalogusId!: string
	public title!: string
	public summary!: string
	public description!: string
	public search!: string
	public publications!: string
	public directory!: string
	public metadata!: string[]
	public status!: string
	public statusCode!: number
	public lastSync!: string | Date
	public available!: boolean
	public default!: boolean
	public organization!: string | TOrganization
	public publicationTypes!: any[]

	/**
	 * Creates a new Listing instance
	 *
	 * @param data - Listing data
	 *
	 * @spec openspec/specs/entity-typescript-models/spec.md
	 */
	constructor(data: TListing) {
		this.hydrate(data)
	}

	/**
	 * Hydrates the listing instance with data
	 *
	 * @param data - Listing data to hydrate with
	 * @private
	 */
	private hydrate(data: TListing) {
		this.id = data?.id?.toString() || ''
		Object.assign(this, fillDefaults(data, LISTING_DEFAULTS))
	}

	/**
	 * Validates the listing data
	 *
	 * @return ZodSafeParseResult containing validation results
	 */
	public validate(): ZodSafeParseResult<unknown> {
		// https://conduction.stoplight.io/docs/open-catalogi/8azwyic71djee-create-listing
		const schema = z.object({
			catalogusId: z.string(),
			title: z.string().min(1, 'is verplicht'),
			summary: z.string().min(1, 'is verplicht'),
			description: z.string(),
			search: z.string().url().or(z.literal('')),
			publications: z.string().url().or(z.literal('')),
			directory: z.string().url().or(z.literal('')),
			metadata: z.string().array(),
			status: z.string(),
			statusCode: z.number().min(200),
			lastSync: z.string().datetime().or(z.literal('')),
			available: z.boolean(),
			default: z.boolean(),
		})

		return schema.safeParse({
			...this,
		})
	}
}
