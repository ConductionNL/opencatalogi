<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnDetailPage
		:title="catalog?.title || t('opencatalogi', 'Catalog')"
		:description="catalog?.summary || ''"
		icon="DatabaseEyeOutline"
		:loading="loading"
		:loading-label="t('opencatalogi', 'Loading catalog...')"
		:empty="!catalog && !loading"
		:empty-label="t('opencatalogi', 'Catalog not found')"
		:error="!!error"
		:error-message="error"
		:on-retry="loadCatalog"
		:layout="detailLayout"
		:widgets="widgetDefs"
		:sidebar="!!catalog"
		:sidebar-open="sidebarOpen"
		object-type="catalog"
		:object-id="catalogId"
		:sidebar-props="{ register: String(catalog?.['@self']?.register || ''), schema: String(catalog?.['@self']?.schema || '') }">
		<!-- Header actions -->
		<template #actions>
			<NcButton @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('opencatalogi', 'Back') }}
			</NcButton>
			<NcButton type="primary" @click="editCatalog">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t('opencatalogi', 'Edit') }}
			</NcButton>
			<NcButton @click="openPublications">
				<template #icon>
					<OpenInApp :size="20" />
				</template>
				{{ t('opencatalogi', 'View Publications') }}
			</NcButton>
		</template>

		<!-- Metadata widget -->
		<template #widget-metadata>
			<CnDetailGrid
				:items="metadataItems"
				layout="horizontal" />
		</template>

		<!-- Description widget -->
		<template #widget-description>
			<div class="description-content">
				<p v-if="catalog?.description">
					{{ catalog.description }}
				</p>
				<p v-else class="empty-text">
					{{ t('opencatalogi', 'No description provided') }}
				</p>
			</div>
		</template>

		<!-- Configuration widget -->
		<template #widget-configuration>
			<CnDetailGrid
				:items="configItems"
				layout="horizontal" />
		</template>

		<!-- Raw data widget -->
		<template #widget-raw-data>
			<CnJsonViewer
				:value="JSON.stringify(catalog, null, 2)"
				language="json"
				:read-only="true"
				:height="300" />
		</template>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailGrid, CnJsonViewer } from '@conduction/nextcloud-vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'

const DETAIL_LAYOUT = [
	{ id: 1, widgetId: 'metadata', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 },
	{ id: 2, widgetId: 'configuration', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 4 },
	{ id: 3, widgetId: 'description', gridX: 0, gridY: 4, gridWidth: 12, gridHeight: 2 },
	{ id: 4, widgetId: 'raw-data', gridX: 0, gridY: 6, gridWidth: 12, gridHeight: 4 },
]

export default {
	name: 'CatalogDetailPage',
	components: {
		CnDetailPage,
		CnDetailGrid,
		CnJsonViewer,
		NcButton,
		ArrowLeft,
		Pencil,
		OpenInApp,
	},
	data() {
		return {
			loading: false,
			error: null,
			sidebarOpen: true,
			detailLayout: [...DETAIL_LAYOUT],
		}
	},
	computed: {
		catalogId() {
			return this.$route.params.id
		},
		catalog() {
			return objectStore.getActiveObject('catalog')
		},
		metadataItems() {
			if (!this.catalog) return []
			const self = this.catalog['@self'] || {}
			return [
				{ label: t('opencatalogi', 'Title'), value: this.catalog.title || '-' },
				{ label: t('opencatalogi', 'Summary'), value: this.catalog.summary || '-' },
				{ label: t('opencatalogi', 'Slug'), value: this.catalog.slug || '-' },
				{ label: t('opencatalogi', 'Status'), value: this.catalog.status || '-' },
				{ label: t('opencatalogi', 'Listed'), value: this.catalog.listed ? t('opencatalogi', 'Public') : t('opencatalogi', 'Private') },
				{ label: t('opencatalogi', 'Created'), value: self.created ? new Date(self.created).toLocaleString() : '-' },
				{ label: t('opencatalogi', 'Updated'), value: self.updated ? new Date(self.updated).toLocaleString() : '-' },
				{ label: t('opencatalogi', 'ID'), value: self.id || this.catalog.id || '-' },
			]
		},
		configItems() {
			if (!this.catalog) return []
			return [
				{ label: t('opencatalogi', 'Registers'), value: (this.catalog.registers || []).length },
				{ label: t('opencatalogi', 'Schemas'), value: (this.catalog.schemas || []).length },
				{ label: t('opencatalogi', 'WOO Sitemap'), value: this.catalog.hasWooSitemap ? t('opencatalogi', 'Yes') : t('opencatalogi', 'No') },
			]
		},
		widgetDefs() {
			return [
				{ id: 'metadata', title: t('opencatalogi', 'Metadata'), type: 'custom' },
				{ id: 'configuration', title: t('opencatalogi', 'Configuration'), type: 'custom' },
				{ id: 'description', title: t('opencatalogi', 'Description'), type: 'custom' },
				{ id: 'raw-data', title: t('opencatalogi', 'Data'), type: 'custom' },
			]
		},
	},
	watch: {
		catalogId: {
			immediate: true,
			handler() {
				if (this.catalogId) {
					this.loadCatalog()
				}
			},
		},
	},
	methods: {
		async loadCatalog() {
			this.loading = true
			this.error = null
			try {
				await objectStore.fetchObject('catalog', this.catalogId)
			} catch (err) {
				this.error = err.message
			} finally {
				this.loading = false
			}
		},
		goBack() {
			this.$router.push({ name: 'Catalogs' })
		},
		editCatalog() {
			objectStore.setActiveObject('catalog', this.catalog)
			navigationStore.setModal('catalog')
		},
		openPublications() {
			if (this.catalog?.slug) {
				this.$router.push({ name: 'Publications', params: { catalogSlug: this.catalog.slug } })
			}
		},
	},
}
</script>

<style scoped>
.description-content {
	padding: 12px 16px;
	line-height: 1.6;
}

.empty-text {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 12px 16px;
}
</style>
