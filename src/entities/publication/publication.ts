import { TAttachment, TCatalogi, TMetadata } from '../'
import { TPublication } from './publication.types'
import { SafeParseReturnType, z } from 'zod'

export class Publication implements TPublication {

	public id: string
	public title: string
	public summary: string
	public description: string
	public reference: string
	public image: string
	public category: string
	public portal: string
	public featured: boolean
	public schema: string
	public status: 'Concept' | 'Published' | 'Withdrawn' | 'Archived' | 'revised' | 'Rejected'
	public attachments: TAttachment[]
	public attachmentCount: number
	public themes: string[]
	public data: Record<string, unknown>

	public anonymization: {
        anonymized: boolean
        results: string
    }

	public language: {
        code: string
        level: string
    }

	public published: string | Date
	public modified: string | Date
	public license: string
	public archive: {
        date: string | Date
    }

	public geo: {
        type: 'Point' | 'LineString' | 'Polygon' | 'MultiPoint' | 'MultiLineString' | 'MultiPolygon'
        coordinates: [number, number]
    }

	public catalogi: TCatalogi
	public metaData: string | TMetadata

	constructor(data: TPublication) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	private hydrate(data: TPublication) {
		this.id = data.id?.toString()
		this.title = data.title || ''
		this.summary = data.summary || ''
		this.description = data.description || ''
		this.reference = data.reference || ''
		this.image = data.image || ''
		this.category = data.category || ''
		this.portal = data.portal || ''
		this.featured = (typeof data.featured === 'boolean' && data.featured)
            // backend can send true and false back as "1" and "" (yes. not "0")
            // FIXME: remove once bug is fixed
            || (typeof data.featured === 'string' && !!parseInt(data.featured))
            || false
		this.schema = data.schema || ''
		this.status = data.status || 'Concept'
		this.attachments = data.attachments || []
		this.attachmentCount = this.attachmentCount || data.attachments?.length || 0
		this.themes = data.themes || []
		this.data = (!Array.isArray(data.data) && data.data) || {}

		this.anonymization = data.anonymization || {
			anonymized: false,
			results: '',
		}

		this.language = data.language || {
			code: '',
			level: '',
		}

		this.published = data.published || ''
		this.modified = data.modified || ''
		this.license = data.license || ''
		this.archive = data.archive || {
			date: '',
		}

		this.geo = data.geo || {
			type: 'Point',
			coordinates: [0, 0],
		}

		// @ts-expect-error -- im not gonna bother rewriting the catalogi structure here
		this.catalogi = data.catalogi || {}
		// @ts-expect-error -- for backwards compatibility metadata will be used if metaData cannot be found
		this.metaData = (data.metaData ?? data.metadata) || ''
	}

	/* istanbul ignore next */
	public validate(): SafeParseReturnType<TPublication, unknown> {
		// https://conduction.stoplight.io/docs/open-catalogi/jcrqsdtnjtx8v-create-publication
		const schema = z.object({
			title: z.string().min(1), // .min(1) on a string functionally works the same as a nonEmpty check (SHOULD NOT BE COMBINED WITH .OPTIONAL())
			summary: z.string().min(1),
			description: z.string(),
			reference: z.string(),
			image: z.string().url().or(z.literal('')), // its either a URL or empty
			category: z.string(),
			portal: z.string().url().or(z.literal('')),
			featured: z.boolean(),
			schema: z.string().url().min(1),
			status: z.enum(['Concept', 'Published', 'Withdrawn', 'Archived', 'Revised', 'Rejected']),
			attachments: z.object({
				reference: z.string().max(255),
				title: z.string().max(255), // .min(1) on a string functionally works the same as a nonEmpty check (SHOULD NOT BE COMBINED WITH .OPTIONAL())
				summary: z.string().max(255),
				description: z.string().max(2555),
				labels: z.string().array(),
				accessUrl: z.string().url().or(z.literal('')),
				downloadUrl: z.string().url().or(z.literal('')),
				type: z.string(),
				anonymization: z.object({
					anonymized: z.boolean(),
					results: z.string().max(2500),
				}),
				language: z.object({
				    // this regex checks if the code has either 2 or 3 characters per group, and the -aaa after the first is optional
					code: z.string()
						.max(7)
						.regex(/^([a-z]{2,3})(-[a-z]{2,3})?$/g, 'language code is not a valid ISO 639-1 code (e.g. en-us)')
						.or(z.literal('')),
					level: z.string()
						.max(2)
						.regex(/^(A|B|C)(1|2)$/g, 'language level is not a valid CEFRL level (e.g. A1)')
						.or(z.literal('')),
				}),
				versionOf: z.string(),
				published: z.string().datetime().or(z.literal('')),
				license: z.string(),
			}).array(),
			attachmentCount: z.number(),
			themes: z.string().array(),
			data: z.record(z.string(), z.any()),
			anonymization: z.object({
				anonymized: z.boolean(),
				results: z.string().max(2500),
			}),
			language: z.object({
				// this regex checks if the code has either 2 or 3 characters per group, and the -aaa after the first is optional
				code: z.string()
					.max(7)
					.regex(/^([a-z]{2,3})(-[a-z]{2,3})?$/g, 'language code is not a valid ISO 639-1 code (e.g. en-us)')
					.or(z.literal('')),
				level: z.string()
					.max(2)
					.regex(/^(A|B|C)(1|2)$/g, 'language level is not a valid CEFRL level (e.g. A1)')
					.or(z.literal('')),
			}),
			published: z.string(),
			modified: z.string(),
			license: z.string(),
			archive: z.object({
				date: z.string().datetime().or(z.literal('')),
			}),
			geo: z.object({
				type: z.enum(['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon']),
				coordinates: z.number().array().min(2).max(2),
			}),
			catalogi: z.string(),
		})

		const result = schema.safeParse({
			...this,
		})

		return result
	}

}
