<template>
	<EntityDetailPage
		entity-type="theme"
		:entity-label="t('opencatalogi', 'Theme')"
		icon="ShapeOutline"
		api-path="themes"
		back-route="Themes"
		edit-modal="theme"
		:extra-metadata="extraMetadata" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import EntityDetailPage from '../shared/EntityDetailPage.vue'

export default {
	name: 'ThemeDetailPage',
	components: { EntityDetailPage },
	methods: {
		t,
		extraMetadata(entity) {
			const items = []
			if (entity.status) {
				items.push({ label: t('opencatalogi', 'Status'), value: entity.status })
			}
			return items
		},
	},
}
</script>
