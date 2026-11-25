/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The markdown editor (v-md-editor + highlight.js, ~1.4 MB minified) loads when
 * a markdown field renders, not on every page boot: the entry points must not
 * import it, and MarkdownEditor.vue registers it as an async component.
 *
 * @spec openspec/specs/generic-object-modals/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

describe('markdown editor on demand', () => {
	it.each(['src/main.js', 'src/settings.js'])('%s does not import the editor or highlight.js', (file) => {
		const source = read(file)
		expect(source).not.toMatch(/from '@kangc\/v-md-editor'/)
		expect(source).not.toMatch(/from 'highlight\.js'/)
		expect(source).not.toMatch(/app\.use\(VueMarkdownEditor\)/)
	})

	it('MarkdownEditor.vue registers the editor as an async component', () => {
		const source = read('src/components/MarkdownEditor.vue')
		expect(source).toMatch(/VMdEditor: defineAsyncComponent\(\(\) => import\([^)]*'\.\/markdownEditorSetup\.js'\)\)/)
	})

	it('the setup module configures the theme, highlighter and language once', () => {
		const source = read('src/components/markdownEditorSetup.js')
		expect(source).toMatch(/VueMarkdownEditor\.use\(githubTheme, \{ Hljs: hljs \}\)/)
		expect(source).toMatch(/VueMarkdownEditor\.lang\.use\('en-US', enUS\)/)
		expect(source).toMatch(/export default VueMarkdownEditor/)
	})
})
