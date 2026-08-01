/**
 * Dashboard bundle entry-point that registers the catalogs widget.
 *
 * @spec openspec/specs/catalogs/spec.md
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import CatalogiWidget from './views/widgets/CatalogiWidget.vue'

// Vue 3: `Vue.extend()` + `new View({ propsData })` + `$mount()` are all gone.
// `createApp(Component, props)` takes the props directly, and `app.mixin()`
// scopes the l10n helpers to THIS widget's app instead of leaking them onto
// every Vue instance on the page (which the global `Vue.mixin` did).
OCA.Dashboard.register('opencatalogi_catalogi_widget', async (el, { widget }) => {
	const app = createApp(CatalogiWidget, { title: widget.title })
	app.mixin({ methods: { t, n } })
	app.mount(el)
})
