<script setup>
import { navigationStore, objectStore } from '../store/store.js'
</script>

<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationNew text="Create Publication" @click="navigationStore.setModal('viewObject'); navigationStore.setTransferData('ignore selectedCatalogus'); objectStore.setActiveObject('publication', null)">
				<template #icon>
					<Plus :size="20" />
				</template>
			</NcAppNavigationNew>
			<NcAppNavigationItem :active="$route.path === '/'" name="Dashboard" @click="handleNavigate('/')">
				<template #icon>
					<Finance :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem v-for="(catalogus, i) in objectStore.getCollection('catalog').results"
				:key="`${catalogus}${i}`"
				:name="catalogus?.title"
				:active="$route.path.startsWith('/publications/') && catalogus?.slug && catalogus.slug.toString() === $route.params.catalogSlug"
				@click="catalogus?.slug && handleNavigate(`/publications/${catalogus.slug}`)">
				<template #icon>
					<DatabaseEyeOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/search'" name="Search" @click="handleNavigate('/search')">
				<template #icon>
					<LayersSearchOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem name="Documentation" @click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>

		<NcAppNavigationSettings>
			<NcAppNavigationItem :active="$route.path === '/organizations'" name="Organizations" @click="handleNavigate('/organizations')">
				<template #icon>
					<OfficeBuildingOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/catalogi'" name="Catalogs" @click="handleNavigate('/catalogi')">
				<template #icon>
					<DatabaseCogOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/glossary'" name="Glossary" @click="handleNavigate('/glossary')">
				<template #icon>
					<FormatListBulleted :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/themes'" name="Themes" @click="handleNavigate('/themes')">
				<template #icon>
					<ShapeOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/pages'" name="Pages" @click="handleNavigate('/pages')">
				<template #icon>
					<Web :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/menus'" name="Menus" @click="handleNavigate('/menus')">
				<template #icon>
					<MenuClose :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path === '/directory'" name="Directory" @click="handleNavigate('/directory')">
				<template #icon>
					<LayersOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationSettings>
	</NcAppNavigation>
</template>
<script>

import {
	NcAppNavigation,
	NcAppNavigationList,
	NcAppNavigationItem,
	NcAppNavigationNew,
	NcAppNavigationSettings,
} from '@nextcloud/vue'

// Icons

import Plus from 'vue-material-design-icons/Plus.vue'
import DatabaseEyeOutline from 'vue-material-design-icons/DatabaseEyeOutline.vue'
import DatabaseCogOutline from 'vue-material-design-icons/DatabaseCogOutline.vue'
import LayersSearchOutline from 'vue-material-design-icons/LayersSearchOutline.vue'
import LayersOutline from 'vue-material-design-icons/LayersOutline.vue'
import Finance from 'vue-material-design-icons/Finance.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'
import MenuClose from 'vue-material-design-icons/MenuClose.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'

export default {
	name: 'MainMenu',
	components: {
		// components
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcAppNavigationSettings,
		// icons
		Plus,
		DatabaseEyeOutline,
		DatabaseCogOutline,
		LayersSearchOutline,
		LayersOutline,
		Finance,
		BookOpenVariantOutline,
		OfficeBuildingOutline,
		ShapeOutline,
		Web,
		MenuClose,
		FormatListBulleted,
	},
	data() {
		return {

			// all of this is settings and should be moved
			settingsOpen: false,
			orc_location: '',
			orc_key: '',
			drc_location: '',
			drc_key: '',
			elastic_location: '',
			elastic_key: '',
			loading: true,
			organization_name: '',
			organization_oin: '',
			organization_pki: '',
			configuration: {
				external: false,
				drcLocation: '',
				drcKey: '',
				orcLocation: '',
				orcKey: '',
				elasticLocation: '',
				elasticKey: '',
				elasticIndex: '',
				mongodbLocation: '',
				mongodbKey: '',
				mongodbCluster: '',
				organizationName: '',
				organizationOin: '',
				organizationPki: '',
			},
			configurationSuccess: -1,
			feedbackPosition: '',
			debounceTimeout: false,
		}
	},
	methods: {
		handleNavigate(path) {
			this.$router.push(path)
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>
<style>
table {
	table-layout: fixed;
}

td.row-name {
	padding-inline-start: 16px;
}

td.row-size {
	text-align: right;
	padding-inline-end: 16px;
}

.table-header {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.sort-icon {
	color: var(--color-text-maxcontrast);
	position: relative;
	inset-inline: -10px;
}

.row-size .sort-icon {
	inset-inline: 10px;
}
</style>
