/* eslint-disable @typescript-eslint/no-explicit-any */
import { SafeParseReturnType, z } from 'zod'
import { TPage } from './page.types'

/**
 * Page class representing a page entity with validation
 * Implements the TPage interface for type safety
 */
export class Page implements TPage {

	public id: string
	public title: string
	public slug: string
	public contents: { type: string; id: string; data: Record<string, any> }[] | null

	/**
	 * Creates a new Page instance
	 * @param data Initial page data conforming to TPage interface
	 */
	constructor(data: TPage) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	/**
	 * Hydrates the page object with provided data
	 * @param data Page data to populate the instance
	 */
	private hydrate(data: TPage) {
		this.id = data?.id?.toString() || ''
		this.title = data?.title || ''
		this.slug = data?.slug || ''
		this.contents = Array.isArray(data?.contents) && data.contents.length > 0 ? data.contents : null
		// created/updated timestamps are not tracked on the entity level
	}

	/* istanbul ignore next */
	/**
	 * Validates the page data against a schema
	 * @return {SafeParseReturnType<TPage, unknown>} containing validation result
	 */
	public validate(): SafeParseReturnType<TPage, unknown> {
		// Schema validation for page data
		const schema = z.object({
			title: z.string().min(1, 'title is verplicht'),
			slug: z.string()
				.min(1, 'slug is verplicht')
				.regex(/^[a-z0-9-]+$/g, 'een slug mag alleen kleine letters, cijfers en streepjes bevatten'),
			contents: z.array(
				z.object({
					type: z.string().min(1, 'type is verplicht'),
					id: z.string(),
					data: z.record(z.string(), z.any()),
				}),
			).nullable(),
		})

		const result = schema.safeParse({
			...this,
		})

		return result
	}

}
