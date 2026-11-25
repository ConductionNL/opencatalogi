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
import { describe, expect, it, vi } from 'vitest'

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

describe('modals on demand', () => {
	// Each modal that renders only while open is an async component, so its
	// CodeMirror / editor / picker code is not parsed on every page boot.
	const source = read('src/modals/Modals.vue')
	const lazy = ['ObjectModal', 'ViewObject', 'UploadObject', 'DownloadObject', 'MergeObject', 'MigrationObject', 'EditAttachmentModal', 'UploadFilesModal']

	it.each(lazy)('%s is loaded with defineAsyncComponent', (name) => {
		expect(source).toMatch(new RegExp('const ' + name + ' = defineAsyncComponent\\(\\(\\) => import\\('))
		expect(source).not.toMatch(new RegExp('^import ' + name + ' ', 'm'))
	})

	it.each(lazy)('%s renders only while its modal is open', (name) => {
		expect(source).toMatch(new RegExp('<' + name + '\\s+v-if="navigationStore\\.(modal|dialog) === '))
	})
})

describe('markdownEditorSetup.js', () => {
	it('configures the editor once and returns it', async () => {
		vi.resetModules()
		const editor = { use: vi.fn(), lang: { use: vi.fn() } }
		vi.doMock('@kangc/v-md-editor', () => ({ default: editor }))
		vi.doMock('@kangc/v-md-editor/lib/lang/en-US.js', () => ({ default: { lang: 'en-US' } }))
		vi.doMock('@kangc/v-md-editor/lib/theme/github.js', () => ({ default: { theme: 'github' } }))
		vi.doMock('highlight.js', () => ({ default: { hljs: true } }))
		vi.doMock('@kangc/v-md-editor/lib/style/base-editor.css', () => ({}))
		vi.doMock('@kangc/v-md-editor/lib/theme/style/github.css', () => ({}))
		const module = await import('../../src/components/markdownEditorSetup.js')
		expect(module.default).toBe(editor)
		expect(editor.use).toHaveBeenCalledExactlyOnceWith({ theme: 'github' }, { Hljs: { hljs: true } })
		expect(editor.lang.use).toHaveBeenCalledExactlyOnceWith('en-US', { lang: 'en-US' })
	})
})
