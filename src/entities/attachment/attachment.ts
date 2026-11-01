import type { ZodSafeParseResult } from 'zod'
import type { TAttachment } from './attachment.types'

import { z } from 'zod'
import { languageSchema } from '../languageSchema.js'

type TStatus =
	'Concept' | 'Published' | 'Withdrawn' | 'Archived' | 'Revised' | 'Rejected'

/**
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 */
export class Attachment implements TAttachment {
	public id!: string
	public reference!: string
	public title!: string
	public summary!: string
	public description!: string
	public labels!: string[]
	public accessUrl!: string
	public downloadUrl!: string
	public status!: TStatus
	public type!: string
	public extension!: string
	public size!: string
	public anonymization!: {
		anonymized: boolean
		results: string
	}

	public language!: {
		code: string
		level: string
	}

	public versionOf!: string
	public hash!: string
	public published!: string | Date | null
	public modified!: string | Date
	public license!: string

	/**
	 * @param data
	 * @spec openspec/specs/entity-typescript-models/spec.md
	 */
	constructor(data: TAttachment) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	private hydrate(data: TAttachment) {
		this.id = data.id?.toString() || ''
		this.reference = data.reference || ''
		this.title = data.title || ''
		this.summary = data.summary || ''
		this.description = data.description || ''
		this.labels = data.labels || []
		this.accessUrl = data.accessUrl || ''
		this.downloadUrl = data.downloadUrl || ''
		this.status = (data.status as TStatus) || 'Concept'
		this.type = data.type || ''
		this.extension = data.extension || ''
		this.size = data.size || ''
		this.anonymization = (!Array.isArray(data.anonymization)
			&& data.anonymization) || {
			anonymized: false,
			results: '',
		}

		this.language = (!Array.isArray(data.language) && data.language) || {
			code: '',
			level: '',
		}

		this.versionOf = data.versionOf || ''
		this.hash = data.hash || ''
		this.published = data.published || null
		this.modified = data.modified || ''
		this.license = data.license || ''
	}

	/* istanbul ignore next */
	public validate(): ZodSafeParseResult<unknown> {
		// https://conduction.stoplight.io/docs/open-catalogi/lsigtx7cafbr7-create-attachment
		const schema = z.object({
			reference: z.string().max(255, 'kan niet langer dan 255 zijn'),
			title: z.string().max(255, 'kan niet langer dan 255 zijn'), // .min(1) on a string functionally works the same as a nonEmpty check (SHOULD NOT BE COMBINED WITH .OPTIONAL())
			summary: z.string().max(255, 'kan niet langer dan 255 zijn'),
			description: z.string().max(2555, 'kan niet langer dan 2555 zijn'),
			labels: z.string().array(),
			accessUrl: z.string().url('is niet een url').or(z.literal('')),
			downloadUrl: z.string().url('is niet een url').or(z.literal('')),
			status: z.enum([
				'Concept',
				'Published',
				'Withdrawn',
				'Archived',
				'Revised',
				'Rejected',
			]),
			type: z.string(),
			anonymization: z.object({
				anonymized: z.boolean().or(z.enum(['true', 'false'])), // because the backend turns booleans into strings for some stupid reason
				results: z.string().max(2500, 'kan niet langer dan 2500 zijn'),
			}),
			language: languageSchema(),
			versionOf: z.string(),
			published: z
				.string()
				.datetime({ offset: true, message: 'is niet een geldige date-time' })
				.or(z.null()),
			license: z.string(),
		})

		return schema.safeParse({ ...this })
	}
}
