/**
 * Dashboard bundle entry-point registering the unpublished-attachments widget.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import UnpublishedAttachmentsWidget from './views/widgets/UnpublishedAttachmentsWidget.vue'

OCA.Dashboard.register('opencatalogi_unpublished_attachments_widget', async (el, { widget }) => {
	const app = createApp(UnpublishedAttachmentsWidget, { title: widget.title })
	app.mixin({ methods: { t, n } })
	app.mount(el)
})
