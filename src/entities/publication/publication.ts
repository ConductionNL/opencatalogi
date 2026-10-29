/* eslint-disable @typescript-eslint/no-explicit-any */
import type { ZodSafeParseResult } from 'zod'
import type { TCatalogi } from '../'
import type { TPublication } from './publication.types'

import { z } from 'zod'

type TStatus =
	'Concept' | 'Published' | 'Withdrawn' | 'Archived' | 'Revised' | 'Rejected'

/**
 * The value when it is a truthy non-array, otherwise the fallback.
 *
 * @param value - The value from the publication data
 * @param fallback - What to use instead
 * @return The value or the fallback
 */
function objectOr<T>(value: unknown, fallback: T): T {
	return ((!Array.isArray(value) && value) || fallback) as T
}

/**
 * Read the featured flag. The backend can send true and false as "1" and ""
 * (not "0"), so a numeric string counts by its number.
 *
 * @param value - The featured value from the publication data
 * @return Whether the publication is featured
 */
function featuredFlag(value: unknown): boolean {
	return (typeof value === 'boolean' && value)
		|| (typeof value === 'string' && !!parseInt(value))
		|| false
}

/**
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 */
export class Publication implements TPublication {
	public id!: string
	public title!: string
	public summary!: string
	public description!: string
	public reference!: string
	public image!: string
	public category!: string
	public portal!: string
	public featured!: boolean
	public source!: string
	public status!: TStatus
	public themes!: string[]
	public organization!: string
	public data!: Record<string, unknown>

	public anonymization!: {
		anonymized: boolean
		results: string
	}

	public language!: {
		code: string
		level: string
	}

	public published!: string | Date
	public modified!: string | Date
	public license!: string
	public archive!: {
		date: string | Date
	}

	public geo!: {
		type:
			| 'Point'
			| 'LineString'
			| 'Polygon'
			| 'MultiPoint'
			| 'MultiLineString'
			| 'MultiPolygon'
		coordinates: [number, number]
	}

	public '@self'?: object

	public catalog!: TCatalogi | any
	public register!: number | null
	public schema!: number | null

	/**
	 * @param data
	 * @spec openspec/specs/entity-typescript-models/spec.md
	 */
	constructor(data: TPublication) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	private hydrate(data: TPublication) {
		this.hydrateText(data)
		this.hydrateStructure(data)
	}

	/**
	 * Copy the identity, text and status fields, defaulting each to an empty value.
	 *
	 * @param data - The publication data
	 */
	private hydrateText(data: TPublication) {
		this.id = data.id?.toString()
		this.title = data.title || ''
		this.summary = data.summary || ''
		this.description = data.description || ''
		this.reference = data.reference || ''
		this.image = data.image || ''
		this.organization = data.organization || ''
		this.category = data.category || ''
		this.portal = data.portal || ''
		this.featured = featuredFlag(data.featured)
		this.source = data.source || ''
		this.status = (data.status as TStatus) || 'Concept'
	}

	/**
	 * Copy the lists, nested objects and references; an array where an object
	 * belongs counts as missing.
	 *
	 * @param data - The publication data
	 */
	private hydrateStructure(data: TPublication) {
		this.themes = data.themes || []
		this.data = objectOr(data.data, {})

		this.anonymization = objectOr(data.anonymization, {
			anonymized: false,
			results: '',
		})

		this.language = objectOr(data.language, {
			code: '',
			level: '',
		})

		this.published = data.published || ''
		this.modified = data.modified || ''
		this.license = data.license || ''
		this.archive = objectOr(data.archive, {
			date: '',
		})

		this.geo = objectOr(data.geo, {
			type: 'Point',
			coordinates: [0, 0],
		})

		this['@self'] = objectOr(data['@self'], {})

		this.catalog = objectOr(data.catalog, {})
		this.register = data.register || null
		this.schema = data.schema || null
	}

	/* istanbul ignore next */
	public validate(): ZodSafeParseResult<unknown> {
		// https://conduction.stoplight.io/docs/open-catalogi/jcrqsdtnjtx8v-create-publication
		const schema = z.object({
			title: z.string().min(1, 'is verplicht'), // .min(1) on a string functionally works the same as a nonEmpty check (SHOULD NOT BE COMBINED WITH .OPTIONAL())
			summary: z.string().min(1, 'is verplicht'),
			description: z.string(),
			reference: z.string(),
			image: z.string().url('is niet een url').or(z.literal('')), // its either a URL or empty
			category: z.string(),
			portal: z.string().url('is niet een url').or(z.literal('')),
			featured: z.boolean(),
			source: z.string(),
			organization: z.string(),
			status: z.enum([
				'Concept',
				'Published',
				'Withdrawn',
				'Archived',
				'Revised',
				'Rejected',
			]),
			themes: z.array(z.union([z.string(), z.number()])),
			data: z.record(z.string(), z.any()),
			anonymization: z.object({
				anonymized: z.boolean(),
				results: z.string().max(2500, 'kan niet langer dan 2500 zijn'),
			}),
			language: z.object({
				// this regex checks if the code has either 2 or 3 characters per group, and the -aaa after the first is optional
				code: z
					.string()
					.regex(
						/^([a-z]{2,3})(-[a-z]{2,3})?$/g,
						'is niet een geldige ISO 639-1 code (e.g. en-us)',
					)
					.or(z.literal('')),
				level: z
					.string()
					.regex(
						/^(A|B|C)(1|2)$/g,
						'is niet een geldige CEFRL level (e.g. A1)',
					)
					.or(z.literal('')),
			}),
			published: z.string().datetime({ offset: true }).or(z.literal('')),
			modified: z.string().datetime({ offset: true }).or(z.literal('')),
			license: z.string(),
			archive: z.object({
				date: z.string().datetime().or(z.literal('')),
			}),
			geo: z.object({
				type: z.enum([
					'Point',
					'LineString',
					'Polygon',
					'MultiPoint',
					'MultiLineString',
					'MultiPolygon',
				]),
				coordinates: z.tuple([z.number(), z.number()]),
			}),
			'@self': z.object({}).optional(),
			catalog: z.string().or(z.number()),
			register: z
				.union([z.number(), z.string()])
				.refine((val) => val.toString().length > 0, 'register is verplicht'),
			schema: z
				.union([z.number(), z.string()])
				.refine((val) => val.toString().length > 0, 'schema is verplicht'),
		})

		const result = schema.safeParse({
			...this,
		})

		return result
	}
}
