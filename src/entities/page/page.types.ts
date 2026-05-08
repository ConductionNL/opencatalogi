/* eslint-disable @typescript-eslint/no-explicit-any */

/**
 * Type definition for a page content item
 * Represents individual content blocks within a page
 */
export type TPageContent = {
    type: string
    id: string
    data: Record<string, any>
    groups?: string[]
    hideAfterLogin?: boolean
    hideBeforeLogin?: boolean
}

/**
 * Type definition for a Page object
 * Represents the structure of a page with content and metadata
 */
export type TPage = {
	id: string
	title: string
    contents: TPageContent[] | null
	slug: string
    groups?: string[]
    hideAfterLogin?: boolean
    hideBeforeLogin?: boolean
}
