/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * nextcloud-vue's stylesheet is a linked file (css/nextcloud-vue.css, copied
 * by webpack.config.js), not CSS text inside the -shared-nc-vue chunk. So
 * every page that loads that chunk must link the stylesheet, or its library
 * components render unstyled.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const read = (file) => readFileSync(join(root, file), 'utf8')

const loaders = [
	'templates/index.php',
	'templates/settings/admin.php',
	...readdirSync(join(root, 'lib/Dashboard')).filter((name) => name.endsWith('Widget.php')).map((name) => 'lib/Dashboard/' + name),
].filter((file) => read(file).includes("'-shared-nc-vue'"))

describe('nextcloud-vue stylesheet', () => {
	it('is linked by every page that loads the shared nextcloud-vue chunk', () => {
		expect(loaders.length).toBeGreaterThanOrEqual(7)
		for (const file of loaders) {
			expect(read(file), file).toMatch(/addStyle\([^)]*'nextcloud-vue'\)/)
		}
	})

	it('is no longer inserted from the main bundle', () => {
		expect(read('src/main.js')).not.toMatch(/import\s+'@conduction\/nextcloud-vue\/css\/index\.css'/)
	})

	it('is copied to css/ by the build', () => {
		expect(read('webpack.config.js')).toContain("path.join(__dirname, 'css', 'nextcloud-vue.css')")
	})
})
