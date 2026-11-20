/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app's entry modules, run as the browser runs them: `src/main.js`
 * registers the detail-page widgets, builds one route per manifest page under
 * the app's own base path, translates route labels, and mounts on
 * `#opencatalogi`; `src/settings.js` mounts the admin settings on
 * `#opencatalogi-settings`; `src/pinia.js` is one shared store instance.
 * The UI library, Vue's app and router factories and the .vue components are
 * replaced, because none of them render under Node.
 */

import nodeModule, { syncBuiltinESMExports } from 'node:module'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const lib = vi.hoisted(() => ({
	registerBuiltinDashboardWidgets: vi.fn(),
	registerDashboardWidget: vi.fn(),
	registerIcons: vi.fn(),
	registerTranslations: vi.fn(),
	buildManifest: vi.fn(),
	defaultPageTypes: { index: 'IndexType', detail: 'DetailType' },
	CnPageRenderer: { name: 'CnPageRenderer' },
	CnTreeView: { name: 'CnTreeView' },
	CnRelationshipGraph: { name: 'CnRelationshipGraph' },
	CnFileManager: { name: 'CnFileManager' },
}))
const editor = vi.hoisted(() => ({ use: vi.fn(), lang: { use: vi.fn() } }))
const l10n = vi.hoisted(() => ({
	loadTranslations: vi.fn(),
	translate: vi.fn((app, key) => `${app}:${key}`),
	translatePlural: vi.fn(),
}))
const vueApp = vi.hoisted(() => ({ apps: [] }))
const routerMock = vi.hoisted(() => ({
	createRouter: vi.fn((options) => ({ options })),
	createWebHistory: vi.fn((base) => ({ base })),
}))

vi.mock('@conduction/nextcloud-vue', () => lib)
vi.mock('@kangc/v-md-editor', () => ({ default: editor }))
vi.mock('@kangc/v-md-editor/lib/lang/en-US.js', () => ({ default: { lang: 'en-US' } }))
vi.mock('@kangc/v-md-editor/lib/theme/github.js', () => ({ default: { theme: 'github' } }))
vi.mock('@nextcloud/l10n', () => l10n)
vi.mock('highlight.js', () => ({ default: { hljs: true } }))
vi.mock('vue-router', () => routerMock)
vi.mock('vue', async (importOriginal) => ({
	...(await importOriginal()),
	createApp: vi.fn((root) => {
		const app = {
			root,
			config: { globalProperties: {} },
			mixin: vi.fn(),
			use: vi.fn(),
			mount: vi.fn(),
		}
		vueApp.apps.push(app)
		return app
	}),
}))
vi.mock('../../src/App.vue', () => ({ default: { name: 'App' } }))
vi.mock('../../src/registry.js', () => ({ default: { FederationDirectory: { name: 'FederationDirectory' } } }))
vi.mock('../../src/views/settings/Settings.vue', () => ({ default: { name: 'AdminSettings' } }))
vi.mock('../../src/components/widgets/AuditTrailWidget.vue', () => ({ default: { name: 'AuditTrailWidget' } }))
vi.mock('../../src/components/widgets/NationalAnnounceWidget.vue', () => ({ default: { name: 'NationalAnnounceWidget' } }))
vi.mock('../../src/components/widgets/PlainSummaryDraftWidget.vue', () => ({ default: { name: 'PlainSummaryDraftWidget' } }))
vi.mock('../../src/components/widgets/PublicationAttachmentsWidget.vue', () => ({ default: { name: 'PublicationAttachmentsWidget' } }))
vi.mock('../../src/components/widgets/PublicationLanguagesWidget.vue', () => ({ default: { name: 'PublicationLanguagesWidget' } }))
vi.mock('../../src/components/widgets/PublicationVisibilityWidget.vue', () => ({ default: { name: 'PublicationVisibilityWidget' } }))
vi.mock('../../src/components/widgets/ThemePreviewWidget.vue', () => ({ default: { name: 'ThemePreviewWidget' } }))

const PAGES = [
	{ id: 'Dashboard', route: '/' },
	{ id: 'PublicationDetail', route: '/publications/:id', config: { notFoundRouteLabel: 'Publications' } },
	{ id: 'Settings', route: '/settings', config: { notFoundRouteLabel: 42 } },
]

/**
 * Run `src/main.js` afresh with webpack's `require.context` over two fragments.
 *
 * @param {string} pathname - `window.location.pathname` while it runs
 * @return {Promise<object>} The Vue app it created
 */
async function runMain(pathname = '/index.php/apps/opencatalogi/publications/1') {
	globalThis.window = { location: { pathname } }
	await import('../../src/main.js')
	return vueApp.apps[vueApp.apps.length - 1]
}

// webpack's `require.context` over two manifest fragments. Each module gets
// its own `require` from `createRequire`, so the context is added there.
const fragments = { './b.json': { id: 'b' }, './a.json': { id: 'a' } }
const fragmentContext = (key) => fragments[key]
fragmentContext.keys = () => Object.keys(fragments)
const requireContext = vi.fn(() => fragmentContext)
const originalCreateRequire = nodeModule.createRequire

beforeEach(() => {
	nodeModule.createRequire = (...args) => Object.assign(originalCreateRequire(...args), { context: requireContext })
	syncBuiltinESMExports()
	requireContext.mockClear()
	vi.resetModules()
	vueApp.apps.length = 0
	for (const fn of [lib.registerBuiltinDashboardWidgets, lib.registerDashboardWidget, lib.registerIcons, lib.registerTranslations, editor.use, editor.lang.use, l10n.loadTranslations, routerMock.createRouter, routerMock.createWebHistory]) {
		fn.mockClear()
	}
	lib.registerTranslations.mockImplementation(() => {})
	lib.buildManifest.mockReset().mockImplementation((manifest, fragments, layout) => ({ pages: PAGES, fragments, layout }))
	l10n.loadTranslations.mockReset().mockReturnValue(undefined)
})

afterEach(() => {
	nodeModule.createRequire = originalCreateRequire
	syncBuiltinESMExports()
	delete globalThis.window
	vi.restoreAllMocks()
})

describe('src/main.js', () => {
	it('registers the ten detail-page widgets after the built-in ones', async () => {
		await runMain()

		expect(lib.registerBuiltinDashboardWidgets).toHaveBeenCalledOnce()
		const calls = lib.registerDashboardWidget.mock.calls
		expect(calls.map(([key]) => key)).toEqual([
			'audit-trail',
			'national-announce',
			'publication-visibility',
			'publication-languages',
			'plain-summary-draft',
			'publication-attachments',
			'theme-preview',
			'tree-view',
			'relationship-graph',
			'file-manager',
		])
		for (const [, options] of calls) {
			expect(options.surfaces).toEqual(['detail-page'])
			expect(options.form).toBe(null)
		}
		expect(calls[0][1].renderer).toEqual({ name: 'AuditTrailWidget' })
		expect(calls[3][1].renderer).toEqual({ name: 'PublicationLanguagesWidget' })
		expect(calls[4][1].renderer).toEqual({ name: 'PlainSummaryDraftWidget' })
		expect(calls[5][1].defaultContent).toEqual({ maxDisplay: 100 })
		expect(calls[7][1].renderer).toBe(lib.CnTreeView)
		expect(lib.registerBuiltinDashboardWidgets.mock.invocationCallOrder[0]).toBeLessThan(lib.registerDashboardWidget.mock.invocationCallOrder[0])
	})

	it('merges the manifest fragments in file order, with the menu layout', async () => {
		await runMain()

		const [manifest, fragments, layout] = lib.buildManifest.mock.calls[0]
		expect(Array.isArray(manifest.pages)).toBe(true)
		expect(fragments).toEqual([{ id: 'a' }, { id: 'b' }])
		expect(layout).toBeTypeOf('object')
		expect(requireContext).toHaveBeenCalledWith('./manifest.d/', false, /\.json$/)
	})

	it('builds one route per page, with props only for a route with a parameter, and a catch-all home', async () => {
		await runMain()

		const { routes, history } = routerMock.createRouter.mock.calls[0][0]
		expect(routes.map(({ name, path, props }) => ({ name, path, props }))).toEqual([
			{ name: 'Dashboard', path: '/', props: false },
			{ name: 'PublicationDetail', path: '/publications/:id', props: true },
			{ name: 'Settings', path: '/settings', props: false },
			{ name: undefined, path: '/:pathMatch(.*)*', props: undefined },
		])
		expect(routes[3].redirect).toBe('/')
		expect(routes[0].component).toEqual(lib.CnPageRenderer)
		expect(routes[0].component).not.toBe(lib.CnPageRenderer)
		expect(history).toEqual({ base: '/index.php/apps/opencatalogi' })
	})

	it('takes the base from the address without index.php, and falls back to the generated url', async () => {
		await runMain('/apps/opencatalogi')
		expect(routerMock.createWebHistory).toHaveBeenLastCalledWith('/apps/opencatalogi')

		vi.resetModules()
		await runMain('/somewhere/else')
		expect(routerMock.createWebHistory).toHaveBeenLastCalledWith('/index.php/apps/opencatalogi')
	})

	it('translates a page\'s not-found route label and leaves other pages alone', async () => {
		const app = await runMain()
		const manifest = app.root.render().props.manifest

		expect(manifest.pages[1].config.notFoundRouteLabel).toBe('opencatalogi:Publications')
		expect(manifest.pages[2].config.notFoundRouteLabel).toBe(42)
		expect(manifest.pages[0]).toEqual(PAGES[0])
	})

	it('mounts the app with the manifest, components and page types, and installs editor, store and router', async () => {
		const app = await runMain()
		const vnode = app.root.render()

		expect(vnode.props.customComponents).toEqual({ FederationDirectory: { name: 'FederationDirectory' } })
		expect(vnode.props.pageTypes).toEqual(lib.defaultPageTypes)
		expect(vnode.props.pageTypes).not.toBe(lib.defaultPageTypes)
		expect(app.config.globalProperties.$vMdEditorLang).toBe('en-US')
		expect(app.config.globalProperties.$vMdEditorLangConfig).toEqual({ 'en-US': { lang: 'en-US' } })
		expect(app.use).toHaveBeenCalledTimes(3)
		expect(app.use.mock.calls[0][0]).toBe(editor)
		expect(app.use.mock.calls[2][0]).toEqual({ options: expect.any(Object) })
		expect(app.mount).toHaveBeenCalledExactlyOnceWith('#opencatalogi')
		expect(editor.use).toHaveBeenCalledWith({ theme: 'github' }, { Hljs: { hljs: true } })
		expect(editor.lang.use).toHaveBeenCalledWith('en-US', { lang: 'en-US' })
		expect(lib.registerIcons).toHaveBeenCalledOnce()
	})

	it('still mounts when the translations cannot be registered or loaded', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		lib.registerTranslations.mockImplementation(() => {
			throw new Error('no catalogue')
		})
		l10n.loadTranslations.mockImplementation(() => {
			throw new Error('offline')
		})

		const app = await runMain()

		expect(warn).toHaveBeenCalledWith('[opencatalogi] registerTranslations failed; falling back to English', expect.any(Error))
		expect(app.mount).toHaveBeenCalledWith('#opencatalogi')
	})

	it('swallows a rejected translation load', async () => {
		let rejected
		l10n.loadTranslations.mockImplementation(() => {
			rejected = Promise.reject(new Error('404'))
			return rejected
		})

		const app = await runMain()
		await expect(rejected).rejects.toThrow('404')

		expect(l10n.loadTranslations).toHaveBeenCalledWith('opencatalogi', expect.any(Function))
		expect(app.mount).toHaveBeenCalled()
	})
})

describe('src/settings.js', () => {
	it('mounts the admin settings with the markdown editor on its own host', async () => {
		await import('../../src/settings.js')
		const app = vueApp.apps[vueApp.apps.length - 1]

		expect(app.root).toEqual({ name: 'AdminSettings' })
		expect(app.config.globalProperties.$vMdEditorLang).toBe('en-US')
		expect(app.use).toHaveBeenCalledExactlyOnceWith(editor)
		expect(app.mixin).toHaveBeenCalledOnce()
		expect(Object.keys(app.mixin.mock.calls[0][0].methods)).toEqual(['t', 'n'])
		expect(app.mount).toHaveBeenCalledExactlyOnceWith('#opencatalogi-settings')
	})
})

describe('src/pinia.js', () => {
	it('is one store instance, the same on every import', async () => {
		const first = (await import('../../src/pinia.js')).default
		const again = (await import('../../src/pinia.js')).default

		expect(typeof first.install).toBe('function')
		expect(first.state.value).toEqual({})
		expect(again).toBe(first)
	})
})
