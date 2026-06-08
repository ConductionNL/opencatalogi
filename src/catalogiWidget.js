/**
 * Dashboard bundle entry-point that registers the catalogs widget.
 *
 * @spec openspec/changes/retrofit-2026-05-25-catalogs/tasks.md#task-4
 */
import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import CatalogiWidget from './views/widgets/CatalogiWidget.vue'

OCA.Dashboard.register('opencatalogi_catalogi_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(CatalogiWidget)
	new View({
		propsData: { title: widget.title },
	}).$mount(el)
})
