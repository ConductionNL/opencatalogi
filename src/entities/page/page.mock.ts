import { Page } from './page'
import { TPage } from './page.types'

/**
 * Mock data function that returns an array of page data objects
 * Used for testing and development purposes
 */
export const mockPageData = (): TPage[] => [
	{ // full data
		id: '1',
		title: 'Test Page',
		slug: 'test-page',
		contents: [
			{ type: 'text', id: '1', data: { text: 'Test content' } },
			{ type: 'image', id: '2', data: { url: 'https://example.com/image.jpg' } },
		],
	},
	// @ts-expect-error -- expected missing contents
	{ // partial data
		id: '2',
		title: 'Another Page',
		slug: 'another-page',
	},
	{ // invalid data
		id: '3',
		title: '',
		slug: '',
		contents: [],
	},
]

/**
 * Creates an array of Page instances from provided data or default mock data
 * @param {TPage[]} data Optional array of page data to convert to Page instances
 * @return {Page[]} Array of Page instances
 */
export const mockPage = (data: TPage[] = mockPageData()): Page[] => data.map(item => new Page(item))
