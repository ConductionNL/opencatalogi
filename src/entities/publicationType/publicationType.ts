/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import type { ZodSafeParseResult } from 'zod'
import type { TPublicationType } from './publicationType.types'

import { z } from 'zod'
import { fillDefaults, objectOr } from '../fillDefaults.js'

type TProperty = TPublicationType['properties'][0]

/**
 * The publication type's text fields and required list, with the value each
 * takes when the data leaves it out or sends a falsy one.
 */
const PUBLICATION_TYPE_TEXT_DEFAULTS = {
	title: '',
	description: '',
	summary: '',
	version: '',
	required: [] as string[],
}

/**
 * The limits a property takes instead of null.
 */
const PROPERTY_LIMIT_DEFAULTS = {
	minimum: 0,
	maximum: 0,
	multipleOf: 0,
	minItems: 0,
	maxItems: 0,
	minLength: 0,
	maxLength: 0,
} as Pick<
	TProperty,
	'minimum' | 'maximum' | 'multipleOf' | 'minItems' | 'maxItems' | 'minLength' | 'maxLength'
>

/**
 * Replace each null limit of a property by its default, in place.
 *
 * @param property - One property of the publication type
 */
function fillNullLimits(property: TProperty): void {
	const limits = Object.keys(PROPERTY_LIMIT_DEFAULTS) as Array<keyof typeof PROPERTY_LIMIT_DEFAULTS>
	limits.forEach((key) => {
		if (property[key] === null) {
			property[key] = PROPERTY_LIMIT_DEFAULTS[key]
		}
	})
}
/** The value types a property can have. */
const PROPERTY_TYPES = [
	'string',
	'number',
	'integer',
	'object',
	'array',
	'boolean',
	'dictionary',
] as const

/** The string formats a property can have. */
const PROPERTY_FORMATS = [
	'date',
	'time',
	'duration',
	'date-time',
	'url',
	'uri',
	'uuid',
	'email',
	'idn-email',
	'hostname',
	'idn-hostname',
	'ipv4',
	'ipv6',
	'uri-reference',
	'iri',
	'iri-reference',
	'uri-template',
	'json-pointer',
	'regex',
	'binary',
	'byte',
	'password',
	'rsin',
	'kvk',
	'bsn',
	'oidn',
	'telephone',
] as const

/**
 * The zod shape of what a property is: its name, type and format.
 *
 * @return The descriptive part of the property shape
 */
function propertyDescriptionShape() {
	return {
		title: z.string().min(1, 'is verplicht'),
		description: z.string(),
		type: z.enum(PROPERTY_TYPES),
		format: z
			.enum(PROPERTY_FORMATS)
			.or(z.literal(''))
			.or(z.null()), // in practice I have found this being able to be both '' and null
		pattern: z.string(),
		default: z.union([z.string(), z.boolean()]),
		behavior: z.string(),
	}
}

/**
 * The zod shape of a property's flags, limits and example.
 *
 * @return The rule part of the property shape
 */
function propertyRuleShape() {
	return {
		required: z.boolean(),
		deprecated: z.boolean(),
		minLength: z.number(),
		maxLength: z.number(),
		example: z.string(),
		minimum: z.number(),
		maximum: z.number(),
		multipleOf: z.number(),
		exclusiveMin: z.boolean(),
		exclusiveMax: z.boolean(),
		minItems: z.number(),
		maxItems: z.number(),
	}
}

/**
 * The zod schema of one property of a publication type.
 *
 * @return The property schema
 */
function propertySchema() {
	return z.object({
		...propertyDescriptionShape(),
		...propertyRuleShape(),
	})
}

/**
 * The zod schema of a publication type's archive valuation and class.
 *
 * @return The archive schema
 */
function archiveSchema() {
	return z.object({
		valuation: z.enum(['b', 'v', 'n'], {
			message: "kan alleen 'b', 'v', of 'n' zijn",
		}),
		class: z.number().refine((data: number) => {
			return [1, 2, 3, 4, 5].includes(data)
		}, 'kan alleen 1, 2, 3, 4 of 5 zijn'),
	})
}

/**
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 */
export class PublicationType implements TPublicationType {
	public id!: string
	public title!: string
	public description!: string
	public summary!: string
	public version!: string
	public required!: string[]
	public properties!: TPublicationType['properties']

	public archive!: TPublicationType['archive']

	public source!: string

	/**
	 * @param data
	 * @spec openspec/specs/entity-typescript-models/spec.md
	 */
	constructor(data: TPublicationType) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	private hydrate(data: TPublicationType) {
		this.id = data?.id?.toString() || ''
		Object.assign(this, fillDefaults(data, PUBLICATION_TYPE_TEXT_DEFAULTS))
		// backend (PHP) doesn't know objects so it will return an array if empty
		this.properties = objectOr(data?.properties, {})
		this.archive = objectOr(data?.archive, { valuation: 'n', class: 1 })
		this.source = data?.source || ''

		// convert null's to the respective default value from predefined list of props
		Object.keys(this.properties).forEach((obj) => {
			fillNullLimits(this.properties[obj])
		})
	}

	/* istanbul ignore next */
	public validate(): ZodSafeParseResult<unknown> {
		// https://conduction.stoplight.io/docs/open-catalogi/5og7tj13bkzj5-create-metadata
		const schema = z.object({
			title: z.string().min(1, 'is verplicht'), // .min(1) on a string functionally works the same as a nonEmpty check (SHOULD NOT BE COMBINED WITH .OPTIONAL())
			description: z.string(),
			summary: z.string().min(1, 'is verplicht'),
			version: z.string(),
			required: z.string().array(),
			properties: z.record(z.string(), propertySchema()), // z.record allows for any amount of any keys, with specific type for value validation
			archive: archiveSchema(),
			source: z.string().url('is niet een url').or(z.literal('')),
		})

		return schema.safeParse({ ...this })
	}
}
