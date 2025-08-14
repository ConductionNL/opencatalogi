import { SafeParseReturnType, z } from 'zod'
import { TMenu, TMenuItem } from './menu.types'

/**
 * Menu class representing a navigation menu entity with validation
 * Implements the TMenu interface for type safety
 */
export class Menu implements TMenu {

	public id: string
	public uuid: string
	public title: string
	public position: number
	public items: TMenuItem[] // Array of menu items
	public createdAt: string
	public updatedAt: string

	/**
	 * Creates a new Menu instance
	 * @param data Initial menu data conforming to TMenu interface
	 */
	constructor(data: TMenu) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	/**
	 * Hydrates the menu object with provided data
	 * @param {TMenu} data Menu data to populate the instance
	 */
	private hydrate(data: TMenu) {
		const items = (data?.items || []).map((item) => ({
			...item,
			// Don't add ID - let the backend handle ID generation for new items
		}))

		this.id = data?.id?.toString() || ''
		this.uuid = data?.uuid || ''
		this.title = data?.title || ''
		this.position = data?.position || 0
		this.items = items
		this.createdAt = data?.createdAt || ''
		this.updatedAt = data?.updatedAt || ''
	}

	/* istanbul ignore next */
	/**
	 * Validates the menu data against a schema
	 * @return {SafeParseReturnType<TMenu, unknown>} SafeParseReturnType containing validation result
	 */
	public validate(): SafeParseReturnType<TMenu, unknown> {
		// Schema validation for menu data
		const schema = z.object({
			title: z.string().min(1, 'title is verplicht'),
			position: z.number().min(0, 'positie moet 0 of hoger zijn'),
			items: z.array(z.object({
				id: z.string().optional(),
				order: z.number().min(0, 'order moet 0 of hoger zijn'),
				name: z.string().min(1, 'name is verplicht'),
				slug: z.string().min(1, 'slug is verplicht'),
				link: z.string().optional(),
				description: z.string().optional(),
				icon: z.string().optional(),
				groups: z.array(z.string()).optional(),
				hideAfterInlog: z.boolean().optional(),
				items: z.array(z.object({
					id: z.string().optional(),
					order: z.number().min(0, 'order moet 0 of hoger zijn'),
					name: z.string().min(1, 'name is verplicht'),
					slug: z.string().min(1, 'slug is verplicht'),
					link: z.string().optional(),
					description: z.string().optional(),
					icon: z.string().optional(),
					groups: z.array(z.string()).optional(),
					hideAfterInlog: z.boolean().optional(),
				})),
			})), // At least '[]'
		})

		const result = schema.safeParse({
			...this,
		})

		return result
	}

}
