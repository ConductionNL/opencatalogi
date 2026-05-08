import { SafeParseReturnType, z } from 'zod'
import { TTheme } from './theme.types'

export class Theme implements TTheme {

	public id: string
	public title: string
	public summary: string
	public description: string
	public image: string
	public content: string
	public link: string
	public url: string
	public icon: string
	public isExternal: boolean
	public sort: number

	constructor(data: TTheme) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	private hydrate(data: TTheme) {
		this.id = data?.id?.toString() || ''
		this.title = data?.title || ''
		this.summary = data?.summary || ''
		this.description = data?.description || ''
		this.image = data?.image || ''
		this.content = data?.content || ''
		this.link = data?.link || ''
		this.url = data?.url || ''
		this.icon = data?.icon || ''
		this.isExternal = data?.isExternal || false
		this.sort = data?.sort ?? 0
	}

	/* istanbul ignore next */
	public validate(): SafeParseReturnType<TTheme, unknown> {
		// https://conduction.stoplight.io/docs/open-catalogi/hpksgr0u1cwj8-theme
		const schema = z.object({
			title: z.string().min(1, 'is verplicht'),
			summary: z.string().min(1, 'is verplicht'),
			description: z.string(),
			image: z.string(),
			content: z.string(),
			link: z.string(),
			url: z.string(),
			icon: z.string(),
			isExternal: z.boolean(),
			sort: z.number(),
		})

		const result = schema.safeParse({
			...this,
		})

		return result
	}

}
