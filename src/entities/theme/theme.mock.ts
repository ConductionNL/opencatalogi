import { Theme } from './theme'
import { TTheme } from './theme.types'

export const mockThemeData = (): TTheme[] => [
	{ // full data
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
	{ // partial data
		id: '2',
		title: 'Woo',
		summary: 'a short form summary',
		description: 'a really really long description about this Theme',
	},
	{ // invalid data
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

export const mockTheme = (data: TTheme[] = mockThemeData()): TTheme[] => data.map(item => new Theme(item))
