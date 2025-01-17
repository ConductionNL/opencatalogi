import { SafeParseReturnType, z } from 'zod'
import { TPage } from './page.types'

/**
 * Page class representing a page entity with validation
 * Implements the TPage interface for type safety
 */
export class Page implements TPage {

	public id: string
	public uuid: string
	public name: string
	public slug: string
	public contents: string
	public createdAt: string
	public updatedAt: string

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
		this.uuid = data?.uuid || ''
		this.name = data?.name || ''
		this.slug = data?.slug || ''
		this.contents = data?.contents || '{}'
		this.createdAt = data?.createdAt || ''
		this.updatedAt = data?.updatedAt || ''
	}

	/* istanbul ignore next */
	/**
	 * Validates the page data against a schema
	 * @return SafeParseReturnType containing validation result
	 */
	public validate(): SafeParseReturnType<TPage, unknown> {
		// Schema validation for page data
		const schema = z.object({
			name: z.string().min(1, 'naam is verplicht'),
			slug: z.string().min(1, 'slug is verplicht'),
			contents: z.string().min(1, 'inhoud is verplicht'),
		})

		const result = schema.safeParse({
			...this,
		})

		return result
	}

}
