/**
 * Type definition for a Menu object
 * Represents the structure of a navigation menu with items and metadata
 */
export type TMenu = {
    id: string                 // Unique identifier for the menu
    uuid: string              // UUID for the menu
    name: string              // Display name of the menu
    position: number          // Order/position of the menu in navigation
    items: string             // JSON string containing menu items and their structure
    createdAt: string         // Creation timestamp
    updatedAt: string         // Last update timestamp
}
