/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import type { ZodSafeParseResult } from 'zod'
import type { TTheme } from './theme.types'

import { z } from 'zod'
import { fillDefaults } from '../fillDefaults.js'

/**
 * The theme's text fields and external flag, with the value each takes when
 * the data leaves it out or sends a falsy one.
 */
const THEME_DEFAULTS = {
	title: '',
	summary: '',
	description: '',
	image: '',
	content: '',
	link: '',
	url: '',
	icon: '',
	isExternal: false,
}

/**
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 */
export class Theme implements TTheme {
	public id!: string
	public title!: string
	public summary!: string
	public description!: string
	public image!: string
	public content!: string
	public link!: string
	public url!: string
	public icon!: string
	public isExternal!: boolean
	public sort!: number

	/**
	 * @param data
	 * @spec openspec/specs/entity-typescript-models/spec.md
	 */
	constructor(data: TTheme) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	private hydrate(data: TTheme) {
		this.id = data?.id?.toString() || ''
		Object.assign(this, fillDefaults(data, THEME_DEFAULTS))
		this.sort = data?.sort ?? 0
	}

	/* istanbul ignore next */
	public validate(): ZodSafeParseResult<unknown> {
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

		return schema.safeParse({ ...this })
	}
}
