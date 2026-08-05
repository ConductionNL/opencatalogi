/**
 * Type definitions for Glossary entity
 * @module Entities
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */

export type TGlossary = {
    id: string
    title: string
    summary: string
    description: string
    externalLink: string
    keywords: string[]
}
