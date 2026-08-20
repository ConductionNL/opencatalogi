import type { TTheme } from './theme.types'

import { Theme } from './theme'

/**
 *
 */
export function mockThemeData(): TTheme[] {
	return [
		{
			// full data
			id: '1',
			title: 'Decat',
			summary: 'a short form summary',
			description: 'a really really long description about this Theme',
			image: 'string',
			content: 'some content',
			link: '/themes/1',
			url: 'https://example.com/themes/1',
			icon: 'icon-theme',
			isExternal: false,
			sort: 1,
		},
		// @ts-expect-error -- expected missing properties
		{
			// partial data
			id: '2',
			title: 'Woo',
			summary: 'a short form summary',
			description: 'a really really long description about this Theme',
		},
		{
			// invalid data
			id: '3',
			title: '',
			summary: 'a short form summary',
			description: 'a really really long description about this Theme',
			image: 'string',
			content: '',
			link: '',
			url: '',
			icon: '',
			isExternal: false,
			sort: 0,
		},
	]
}

/**
 *
 * @param data
 */
export function mockTheme(data: TTheme[] = mockThemeData()): TTheme[] {
	return data.map((item) => new Theme(item))
}
