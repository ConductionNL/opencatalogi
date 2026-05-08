<template>
	<EntityDetailPage
		entity-type="menu"
		:entity-label="t('opencatalogi', 'Menu')"
		icon="MenuClose"
		api-path="menus"
		back-route="Menus"
		edit-modal="viewMenu"
		:extra-metadata="extraMetadata" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import EntityDetailPage from '../shared/EntityDetailPage.vue'

export default {
	name: 'MenuDetailPage',
	components: { EntityDetailPage },
	methods: {
		t,
		extraMetadata(entity) {
			const items = []
			if (entity.position) {
				items.push({ label: t('opencatalogi', 'Position'), value: entity.position })
			}
			if (entity.items?.length) {
				items.push({ label: t('opencatalogi', 'Menu Items'), value: String(entity.items.length) })
			}
			return items
		},
	},
}
</script>
