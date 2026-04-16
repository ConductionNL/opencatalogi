<template>
	<EntityDetailPage
		entity-type="page"
		:entity-label="t('opencatalogi', 'Page')"
		icon="Web"
		api-path="pages"
		back-route="Pages"
		edit-modal="viewPage"
		:extra-metadata="extraMetadata" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import EntityDetailPage from '../shared/EntityDetailPage.vue'

export default {
	name: 'PageDetailPage',
	components: { EntityDetailPage },
	methods: {
		t,
		extraMetadata(entity) {
			const items = []
			if (entity.slug) {
				items.push({ label: t('opencatalogi', 'Slug'), value: entity.slug })
			}
			if (entity.contents?.length) {
				items.push({ label: t('opencatalogi', 'Content Items'), value: String(entity.contents.length) })
			}
			return items
		},
	},
}
</script>
