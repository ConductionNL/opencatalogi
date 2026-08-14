import type { TPage } from './page.types'

import { Page } from './page'

/** @typedef {import('./page.types').TPage} TPage */
/** @typedef {import('./page').Page} Page */

/**
 * Mock data function that returns an array of page data objects
 * Used for testing and development purposes
 */
export function mockPageData(): TPage[] {
	return [
		{
			// full data
			id: '1',
			title: 'Test Page',
			slug: 'test-page',
			contents: [
				{
					type: 'text',
					id: '1',
					data: { text: 'Test content' },
					groups: ['admin'],
					hideAfterLogin: false,
					hideBeforeLogin: false,
				},
				{
					type: 'image',
					id: '2',
					data: { url: 'https://example.com/image.jpg' },
					groups: [],
					hideAfterLogin: false,
					hideBeforeLogin: false,
				},
			],
			groups: ['users'],

			hideAfterLogin: false,
			hideBeforeLogin: true,
		},
		// @ts-expect-error -- expected missing contents
		{
			// partial data
			id: '2',
			title: 'Another Page',
			slug: 'another-page',
		},
		{
			// invalid data
			id: '3',
			title: '',
			slug: '',
			contents: [],
		},
	]
}

/**
 * Creates an array of Page instances from provided data or default mock data
 *
 * @param data Optional array of page data to convert to Page instances
 * @return Array of Page instances
 */
export function mockPage(data: TPage[] = mockPageData()): Page[] {
	return data.map((item) => new Page(item))
}
