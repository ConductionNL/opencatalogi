/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Compiles the style blocks of a single-file component the way vue-loader does,
 * including blocks loaded with `src`, and returns every resulting rule as
 * `<at-rule context> <selector> {<declarations>}`, sorted.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { compileStyle, parse } from 'vue/compiler-sfc'

const repoRoot = path.resolve(__dirname, '../../..')

/**
 * Split compiled CSS into rule strings. Handles nested at-rules; comments are dropped.
 *
 * @param {string} css Compiled CSS.
 * @return {string[]} One string per rule, prefixed with its at-rule context.
 */
function cssRules(css) {
	const rules = []
	const context = []
	let buffer = ''
	for (const char of css.replace(/\/\*[\s\S]*?\*\//g, '')) {
		if (char === '{') {
			context.push(buffer.trim().replace(/\s+/g, ' '))
			buffer = ''
		} else if (char === '}') {
			const declarations = buffer
				.split(';')
				.map((declaration) => declaration.trim().replace(/\s*:\s*/, ':'))
				.filter(Boolean)
			const selector = context.pop()
			if (declarations.length > 0 || !selector.startsWith('@')) {
				rules.push(
					`${[...context, selector].join(' ')} {${declarations.join(';')}}`,
				)
			}
			buffer = ''
		} else {
			buffer += char
		}
	}
	return rules
}

/**
 * @param {string} file Component path relative to the repository root.
 * @param {string} root Directory the path is relative to.
 * @return {string[]} The compiled rules, sorted.
 */
export function scopedRules(file, root = repoRoot) {
	const { descriptor } = parse(readFileSync(path.join(root, file), 'utf8'), {
		filename: file,
	})
	const rules = []
	for (const style of descriptor.styles) {
		const source = style.src
			? readFileSync(path.join(root, path.dirname(file), style.src), 'utf8')
			: style.content
		const { code } = compileStyle({
			source,
			filename: file,
			id: 'data-v-golden',
			scoped: Boolean(style.scoped),
		})
		rules.push(...cssRules(code))
	}
	return rules.sort()
}
