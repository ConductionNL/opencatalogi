/* eslint-disable @typescript-eslint/no-explicit-any */

/**
 * Type definition for a page content item
 * Represents individual content blocks within a page
 */
export type TPageContent = {
    type: string
    id: string
    data: Record<string, any>
    /**
     * Nextcloud groups that have access to this content block
     * @phpstan-var string[]|null
     * @psalm-var string[]|null
     */
    groups?: string[]
    /**
     * Whether to hide this content block after user login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
    hideAfterInlog?: boolean
    /**
     * Whether to hide this content block before user login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
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
    /**
     * Nextcloud groups that have access to this page
     * @phpstan-var string[]|null
     * @psalm-var string[]|null
     */
    groups?: string[]
    /**
     * Whether to hide this page after user login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
    hideAfterInlog?: boolean
    /**
     * Whether to hide this page before user login
     * @phpstan-var bool|null
     * @psalm-var bool|null
     */
    hideBeforeLogin?: boolean
}
