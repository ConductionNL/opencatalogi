export type TMenuSubItem = {
    id?: string
    order: number
    name: string
    link: string
    description?: string
    icon?: string
    groups?: string[]
    hideAfterInlog?: boolean
    /**
     * If true, this menu item is hidden before login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
    hideBeforeLogin?: boolean
}

export type TMenuItem = {
    id?: string
    order: number
    name: string
    link: string
    description?: string
    icon?: string
    groups?: string[]
    hideAfterInlog?: boolean
    /**
     * If true, this menu item is hidden before login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
    hideBeforeLogin?: boolean
    items?: TMenuSubItem[]
}

/**
 * Type definition for a Menu object
 * Represents the structure of a navigation menu with items and metadata
 */
export type TMenu = {
	id: string // Unique identifier for the menu
	uuid: string // UUID for the menu
	title: string // Display title of the menu
	position: number // Order/position of the menu in navigation
	items: TMenuItem[] // Array of menu items
	createdAt: string // Creation timestamp
	updatedAt: string // Last update timestamp
    /**
     * Nextcloud groups that have access to this menu
     * @phpstan-var string[]|null
     * @psalm-var string[]|null
     */
    groups?: string[]
    /**
     * Whether to hide this menu after user login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
    hideAfterInlog?: boolean
    /**
     * Whether to hide this menu before user login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
    hideBeforeLogin?: boolean
}
