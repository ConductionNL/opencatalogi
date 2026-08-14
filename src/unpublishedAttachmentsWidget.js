import { translatePlural as n, translate as t } from '@nextcloud/l10n'
/**
 * Dashboard bundle entry-point registering the unpublished-attachments widget.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { createApp } from 'vue'
import UnpublishedAttachmentsWidget from './views/widgets/UnpublishedAttachmentsWidget.vue'

OCA.Dashboard.register(
	'opencatalogi_unpublished_attachments_widget',
	async (el, { widget }) => {
		const app = createApp(UnpublishedAttachmentsWidget, { title: widget.title })
		app.mixin({ methods: { t, n } })
		app.mount(el)
	},
)
