<template>
	<EntityDetailPage
		entity-type="glossary"
		:entity-label="t('opencatalogi', 'Glossary Term')"
		icon="FormatListBulleted"
		api-path="glossary"
		back-route="Glossary"
		edit-modal="glossary"
		:extra-metadata="extraMetadata" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import EntityDetailPage from '../shared/EntityDetailPage.vue'

export default {
	name: 'GlossaryDetailPage',
	components: { EntityDetailPage },
	methods: {
		t,
		extraMetadata(entity) {
			const items = []
			if (entity.keywords?.length) {
				items.push({ label: t('opencatalogi', 'Keywords'), value: entity.keywords.join(', ') })
			}
			return items
		},
	},
}
</script>
