/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dashboard bundle entry-point registering the most-viewed-publications widget.
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import MostViewedPublicationsWidget from './views/widgets/MostViewedPublicationsWidget.vue'

OCA.Dashboard.register(
	'opencatalogi_most_viewed_publications_widget',
	async (el, { widget }) => {
		const app = createApp(MostViewedPublicationsWidget, { title: widget.title })
		app.mixin({ methods: { t, n } })
		app.mount(el)
	},
)
