/**
 * Dashboard bundle entry-point registering the retention review-queue widget.
 *
 * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */
import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import RetentionWidget from './views/widgets/RetentionWidget.vue'

OCA.Dashboard.register('opencatalogi_retention_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(RetentionWidget)
	new View({
		propsData: { title: widget.title },
	}).$mount(el)
})
