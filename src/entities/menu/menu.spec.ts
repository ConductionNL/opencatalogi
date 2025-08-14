/**
 * Menu entity tests
 * @module Entities
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */

/* eslint-disable no-console */
import { Menu } from './menu'
import { mockMenu, mockMinimalMenu } from './menu.mock'
import { TMenu } from './menu.types'

describe('Menu Store', () => {
	it('create Menu entity with full data', () => {
		const menu = new Menu(mockMenu)

		expect(menu).toBeInstanceOf(Menu)
		expect(menu.uuid).toBe(mockMenu.uuid)
		expect(menu.title).toBe(mockMenu.title)
		expect(menu.position).toBe(mockMenu.position)
		expect(menu.items).toHaveLength(1)
		expect(menu.items[0].name).toBe(mockMenu.items[0].name)
		expect(menu.items[0].slug).toBe(mockMenu.items[0].slug)
		expect(menu.items[0].link).toBe(mockMenu.items[0].link)
		expect(menu.items[0].description).toBe(mockMenu.items[0].description)
		expect(menu.items[0].icon).toBe(mockMenu.items[0].icon)
		expect(menu.createdAt).toBe(mockMenu.createdAt)
		expect(menu.updatedAt).toBe(mockMenu.updatedAt)

		expect(menu.validate().success).toBe(true)
	})

	it('create Menu entity with partial data', () => {
		const menu = new Menu(mockMinimalMenu)

		expect(menu).toBeInstanceOf(Menu)
		expect(menu.id).toBe(mockMinimalMenu.id)
		expect(menu.uuid).toBe(mockMinimalMenu.uuid)
		expect(menu.title).toBe(mockMinimalMenu.title)
		expect(menu.position).toBe(1) // Should use the provided position
		expect(menu.items).toEqual([]) // Should be an empty array
		expect(menu.createdAt).toBe(mockMinimalMenu.createdAt)
		expect(menu.updatedAt).toBe(mockMinimalMenu.updatedAt)

		expect(menu.validate().success).toBe(true)
	})

	it('create Menu entity with falsy data', () => {
		const menu = new Menu({} as TMenu)

		expect(menu).toBeInstanceOf(Menu)
		expect(menu.uuid).toBe('')
		expect(menu.title).toBe('')
		expect(menu.items).toEqual([])

		expect(menu.validate().success).toBe(false)
	})
})
