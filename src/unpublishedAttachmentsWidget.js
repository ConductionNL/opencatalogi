/**
 * Dashboard bundle entry-point registering the unpublished-attachments widget.
 *
 * @spec openspec/changes/retrofit-2026-05-25-dashboard/tasks.md#task-3
 */
import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import UnpublishedAttachmentsWidget from './views/widgets/UnpublishedAttachmentsWidget.vue'

OCA.Dashboard.register('opencatalogi_unpublished_attachments_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(UnpublishedAttachmentsWidget)
	new View({
		propsData: { title: widget.title },
	}).$mount(el)
})
