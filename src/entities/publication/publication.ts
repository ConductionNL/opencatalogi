/* eslint-disable @typescript-eslint/no-explicit-any */
import type { ZodSafeParseResult } from 'zod'
import type { TCatalogi } from '../'
import type { TPublication } from './publication.types'

import { z } from 'zod'
import { fillDefaults, objectOr } from '../fillDefaults.js'
import { languageSchema } from '../languageSchema.js'
import { publicationStatusSchema } from '../publicationStatus.js'

type TStatus =
	'Concept' | 'Published' | 'Withdrawn' | 'Archived' | 'Revised' | 'Rejected'

/**
 * Read the featured flag. The backend can send true and false as "1" and ""
 * (not "0"), so a numeric string counts by its number.
 *
 * @param value - The featured value from the publication data
 * @return Whether the publication is featured
 */
function featuredFlag(value: unknown): boolean {
	return (
		(typeof value === 'boolean' && value)
		|| (typeof value === 'string' && !!parseInt(value))
		|| false
	)
}

/**
 * The publication's text and status fields, with the value each takes when
 * the data leaves it out or sends a falsy one. The featured flag holds its
 * place in the field order here and is read by featuredFlag().
 */
const PUBLICATION_TEXT_DEFAULTS = {
	title: '',
	summary: '',
	description: '',
	reference: '',
	image: '',
	organization: '',
	category: '',
	portal: '',
	featured: false,
	source: '',
	status: 'Concept' as TStatus,
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
		Object.assign(this, fillDefaults(data, PUBLICATION_TEXT_DEFAULTS))
		this.featured = featuredFlag(data.featured)
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
			status: publicationStatusSchema(),
			themes: z.array(z.union([z.string(), z.number()])),
			data: z.record(z.string(), z.any()),
			anonymization: z.object({
				anonymized: z.boolean(),
				results: z.string().max(2500, 'kan niet langer dan 2500 zijn'),
			}),
			language: languageSchema(),
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

		return schema.safeParse({ ...this })
	}
}
