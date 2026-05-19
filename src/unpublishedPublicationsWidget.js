import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import UnpublishedPublicationsWidget from './views/widgets/UnpublishedPublicationsWidget.vue'

OCA.Dashboard.register('opencatalogi_unpublished_publications_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(UnpublishedPublicationsWidget)
	new View({
		propsData: { title: widget.title },
	}).$mount(el)
})
