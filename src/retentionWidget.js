import { translatePlural as n, translate as t } from '@nextcloud/l10n'
/**
 * Dashboard bundle entry-point registering the retention review-queue widget.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */
import { createApp } from 'vue'
import RetentionWidget from './views/widgets/RetentionWidget.vue'

OCA.Dashboard.register('opencatalogi_retention_widget', async (el, { widget }) => {
	const app = createApp(RetentionWidget, { title: widget.title })
	app.mixin({ methods: { t, n } })
	app.mount(el)
})
