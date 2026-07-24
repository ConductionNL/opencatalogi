/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dashboard bundle entry-point registering the most-viewed-publications widget.
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import MostViewedPublicationsWidget from './views/widgets/MostViewedPublicationsWidget.vue'

OCA.Dashboard.register('opencatalogi_most_viewed_publications_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(MostViewedPublicationsWidget)
	new View({
		propsData: { title: widget.title },
	}).$mount(el)
})
