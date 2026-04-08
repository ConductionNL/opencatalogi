<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('opencatalogi', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<Finance :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				v-for="catalogus in catalogs"
				:key="catalogus.id || catalogus.slug"
				:name="catalogus.title"
				:to="{ name: 'Publications', params: { catalogSlug: catalogus.slug } }">
				<template #icon>
					<DatabaseEyeOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('opencatalogi', 'Search')"
				:to="{ name: 'Search' }">
				<template #icon>
					<LayersSearchOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('opencatalogi', 'Documentation')"
				@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings>
				<NcAppNavigationItem
					:name="t('opencatalogi', 'Catalogs')"
					:to="{ name: 'Catalogs' }">
					<template #icon>
						<DatabaseCogOutline :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('opencatalogi', 'Glossary')"
					:to="{ name: 'Glossary' }">
					<template #icon>
						<FormatListBulleted :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('opencatalogi', 'Themes')"
					:to="{ name: 'Themes' }">
					<template #icon>
						<ShapeOutline :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('opencatalogi', 'Pages')"
					:to="{ name: 'Pages' }">
					<template #icon>
						<Web :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('opencatalogi', 'Menus')"
					:to="{ name: 'Menus' }">
					<template #icon>
						<MenuClose :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('opencatalogi', 'Directory')"
					:to="{ name: 'Directory' }">
					<template #icon>
						<LayersOutline :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('opencatalogi', 'Settings')"
					@click="$emit('open-settings')">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppNavigationSettings,
} from '@nextcloud/vue'
import { objectStore, navigationStore } from '../store/store.js'
import Finance from 'vue-material-design-icons/Finance.vue'
import DatabaseEyeOutline from 'vue-material-design-icons/DatabaseEyeOutline.vue'
import DatabaseCogOutline from 'vue-material-design-icons/DatabaseCogOutline.vue'
import LayersSearchOutline from 'vue-material-design-icons/LayersSearchOutline.vue'
import LayersOutline from 'vue-material-design-icons/LayersOutline.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'
import MenuClose from 'vue-material-design-icons/MenuClose.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		Finance,
		DatabaseEyeOutline,
		DatabaseCogOutline,
		LayersSearchOutline,
		LayersOutline,
		BookOpenVariantOutline,
		ShapeOutline,
		Web,
		MenuClose,
		FormatListBulleted,
		Cog,
	},
	computed: {
		navigationStore() {
			return navigationStore
		},
		catalogs() {
			const collection = objectStore.getCollection('catalog')
			const results = Array.isArray(collection) ? collection : collection?.results || []
			return results.filter((c) => c && c.slug)
		},
	},
	methods: {
		t,
		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>
