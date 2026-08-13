/**
 * Dashboard bundle entry-point registering the unpublished-publications widget.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import UnpublishedPublicationsWidget from './views/widgets/UnpublishedPublicationsWidget.vue'

OCA.Dashboard.register(
	'opencatalogi_unpublished_publications_widget',
	async (el, { widget }) => {
		const app = createApp(UnpublishedPublicationsWidget, { title: widget.title })
		app.mixin({ methods: { t, n } })
		app.mount(el)
	},
)
