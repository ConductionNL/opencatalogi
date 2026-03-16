<template>
	<div>
		<CnDashboardPage
			:title="t('opencatalogi', 'Dashboard')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="globalLoading && !hasData"
			:empty-label="t('opencatalogi', 'No widgets configured')"
			:unavailable-label="t('opencatalogi', 'Widget not available')"
			@layout-change="onLayoutChange">
			<!-- Header actions -->
			<template #header-actions>
				<NcButton type="primary" @click="createPublication">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('opencatalogi', 'New Publication') }}
				</NcButton>
				<NcButton :disabled="globalLoading"
					:aria-label="t('opencatalogi', 'Refresh dashboard')"
					@click="loadDashboardData">
					<template #icon>
						<Refresh :size="20" :class="{ 'icon-spinning': globalLoading }" />
					</template>
				</NcButton>
			</template>

			<!-- Catalogs count widget -->
			<template #widget-count-catalogs>
				<CnStatsBlock
					:title="t('opencatalogi', 'Catalogs')"
					:count="kpis.catalogCount"
					:count-label="t('opencatalogi', 'catalogs')"
					:icon="DatabaseCogOutline"
					variant="primary"
					horizontal
					:route="{ name: 'Catalogs' }" />
			</template>

			<!-- Publications count widget -->
			<template #widget-count-publications>
				<CnStatsBlock
					:title="t('opencatalogi', 'Publications')"
					:count="kpis.publicationCount"
					:count-label="t('opencatalogi', 'publications')"
					:icon="DatabaseEyeOutline"
					variant="primary"
					horizontal
					:route="{ name: 'Catalogs' }" />
			</template>

			<!-- Concept Publications count widget -->
			<template #widget-count-concept-publications>
				<CnStatsBlock
					:title="t('opencatalogi', 'Concept Publications')"
					:count="kpis.conceptPublicationCount"
					:count-label="t('opencatalogi', 'concept')"
					:icon="FileDocumentEditOutline"
					:variant="kpis.conceptPublicationCount > 0 ? 'warning' : 'default'"
					horizontal
					:route="{ name: 'Catalogs' }" />
			</template>

			<!-- Concept Attachments count widget -->
			<template #widget-count-concept-attachments>
				<CnStatsBlock
					:title="t('opencatalogi', 'Concept Attachments')"
					:count="kpis.conceptAttachmentCount"
					:count-label="t('opencatalogi', 'concept')"
					:icon="PaperclipOff"
					:variant="kpis.conceptAttachmentCount > 0 ? 'warning' : 'default'"
					horizontal />
			</template>

			<!-- Catalogi Overview widget -->
			<template #widget-catalogi>
				<div class="catalogi-widget-content">
					<div v-if="catalogs.length === 0" class="widget-empty">
						{{ t('opencatalogi', 'No catalogs found') }}
					</div>
					<div v-else class="catalogi-list">
						<div
							v-for="catalog in catalogs"
							:key="catalog.id || catalog.slug"
							class="catalogi-item"
							@click="onCatalogClick(catalog)">
							<DatabaseCogOutline :size="20" class="catalogi-item-icon" />
							<div class="catalogi-item-content">
								<span class="catalogi-item-title">{{ catalog.title }}</span>
								<span v-if="catalog.summary" class="catalogi-item-summary">{{ catalog.summary }}</span>
							</div>
						</div>
					</div>
				</div>
			</template>

			<!-- Concept Publications widget -->
			<template #widget-concept-publications>
				<div class="concept-widget-content">
					<div v-if="conceptPublications.length === 0" class="widget-empty">
						{{ t('opencatalogi', 'No concept publications') }}
					</div>
					<div v-else class="concept-list">
						<div
							v-for="publication in conceptPublications"
							:key="publication.id"
							class="concept-item">
							<FileDocumentEditOutline :size="20" class="concept-item-icon" />
							<div class="concept-item-content">
								<span class="concept-item-title">{{ publication.title }}</span>
								<span v-if="publication.summary" class="concept-item-summary">{{ publication.summary }}</span>
							</div>
							<span class="concept-item-status">{{ t('opencatalogi', 'Concept') }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- Concept Attachments widget -->
			<template #widget-concept-attachments>
				<div class="concept-widget-content">
					<div v-if="conceptAttachments.length === 0" class="widget-empty">
						{{ t('opencatalogi', 'No concept attachments') }}
					</div>
					<div v-else class="concept-list">
						<div
							v-for="attachment in conceptAttachments"
							:key="attachment.id"
							class="concept-item">
							<Paperclip :size="20" class="concept-item-icon" />
							<div class="concept-item-content">
								<span class="concept-item-title">{{ attachment.title }}</span>
								<span v-if="attachment.summary" class="concept-item-summary">{{ attachment.summary }}</span>
							</div>
							<span class="concept-item-status">{{ t('opencatalogi', 'Concept') }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- Empty state -->
			<template #empty>
				<div class="welcome-message">
					<p>
						{{ t('opencatalogi', 'Welcome to OpenCatalogi! Your dashboard will show an overview of your catalogs, publications, and attachments.') }}
					</p>
				</div>
			</template>
		</CnDashboardPage>

		<!-- Error display -->
		<div v-if="error" class="dashboard-error">
			<p>{{ error }}</p>
			<NcButton @click="loadDashboardData">
				{{ t('opencatalogi', 'Retry') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import DatabaseCogOutline from 'vue-material-design-icons/DatabaseCogOutline.vue'
import DatabaseEyeOutline from 'vue-material-design-icons/DatabaseEyeOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import PaperclipOff from 'vue-material-design-icons/PaperclipOff.vue'
import { objectStore, navigationStore } from '../../store/store.js'

/**
 * Default dashboard layout -- 4 count tiles across the top row (3 cols each),
 * then catalogi and concept publications side by side, concept attachments full width.
 */
const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'count-catalogs', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-publications', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-concept-publications', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'count-concept-attachments', gridX: 9, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 5, widgetId: 'catalogi', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 6, widgetId: 'concept-publications', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 7, widgetId: 'concept-attachments', gridX: 0, gridY: 6, gridWidth: 12, gridHeight: 4 },
]

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		CnDashboardPage,
		CnStatsBlock,
		Plus,
		Refresh,
		DatabaseCogOutline,
		FileDocumentEditOutline,
		Paperclip,
	},
	data() {
		return {
			// Icon components for CnStatsBlock :icon prop
			DatabaseCogOutline,
			DatabaseEyeOutline,
			FileDocumentEditOutline,
			PaperclipOff,
			globalLoading: false,
			error: null,
			refreshTimer: null,
			dashboardLayout: [...DEFAULT_LAYOUT],
		}
	},
	computed: {
		catalogs() {
			return objectStore.getCollection('catalog').results || []
		},
		allPublications() {
			return objectStore.getCollection('publication').results || []
		},
		conceptPublications() {
			return this.allPublications.filter(
				(publication) => publication.status === 'Concept',
			)
		},
		allAttachments() {
			return objectStore.getCollection('attachment').results || []
		},
		conceptAttachments() {
			return this.allAttachments.filter(
				(attachment) => attachment.status === 'Concept',
			)
		},
		kpis() {
			return {
				catalogCount: this.catalogs.length,
				publicationCount: this.allPublications.length,
				conceptPublicationCount: this.conceptPublications.length,
				conceptAttachmentCount: this.conceptAttachments.length,
			}
		},
		hasData() {
			return this.catalogs.length > 0
				|| this.allPublications.length > 0
				|| this.allAttachments.length > 0
		},
		widgetDefs() {
			return [
				{ id: 'count-catalogs', title: t('opencatalogi', 'Catalogs'), type: 'custom' },
				{ id: 'count-publications', title: t('opencatalogi', 'Publications'), type: 'custom' },
				{ id: 'count-concept-publications', title: t('opencatalogi', 'Concept Publications'), type: 'custom' },
				{ id: 'count-concept-attachments', title: t('opencatalogi', 'Concept Attachments'), type: 'custom' },
				{ id: 'catalogi', title: t('opencatalogi', 'Catalogs Overview'), type: 'custom' },
				{ id: 'concept-publications', title: t('opencatalogi', 'Concept Publications'), type: 'custom' },
				{ id: 'concept-attachments', title: t('opencatalogi', 'Concept Attachments'), type: 'custom' },
			]
		},
	},
	async mounted() {
		await this.loadDashboardData()
		this.refreshTimer = setInterval(() => {
			this.loadDashboardData()
		}, 5 * 60 * 1000)
	},
	beforeDestroy() {
		if (this.refreshTimer) {
			clearInterval(this.refreshTimer)
			this.refreshTimer = null
		}
	},
	methods: {
		async loadDashboardData() {
			this.globalLoading = true
			this.error = null

			try {
				await Promise.allSettled([
					objectStore.fetchCollection('catalog'),
					objectStore.fetchCollection('publication'),
					objectStore.fetchCollection('attachment'),
				])
			} catch (err) {
				this.error = err.message || t('opencatalogi', 'Failed to load dashboard data')
				console.error('Dashboard fetch error:', err)
			} finally {
				this.globalLoading = false
			}
		},

		createPublication() {
			objectStore.clearActiveObject('publication')
			navigationStore.setModal('viewObject')
		},

		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},

		onCatalogClick(catalog) {
			if (catalog?.slug) {
				this.$router.push(`/publications/${catalog.slug}`)
			}
		},
	},
}
</script>

<style scoped>
/* Catalogi widget */
.catalogi-widget-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.catalogi-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.catalogi-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	cursor: pointer;
	border-radius: var(--border-radius);
}

.catalogi-item:hover {
	background: var(--color-background-hover);
}

.catalogi-item-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.catalogi-item-content {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.catalogi-item-title {
	font-size: 14px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.catalogi-item-summary {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Concept widgets (publications & attachments) */
.concept-widget-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.concept-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.concept-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
}

.concept-item-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.concept-item-content {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
}

.concept-item-title {
	font-size: 14px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.concept-item-summary {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.concept-item-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-warning-hover, rgba(233, 163, 0, 0.1));
	color: var(--color-warning-text, #7a5700);
	flex-shrink: 0;
}

/* Empty state */
.widget-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

/* Welcome / error */
.welcome-message {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
}

.dashboard-error {
	text-align: center;
	padding: 20px;
	color: var(--color-error);
}

.dashboard-error p {
	margin-bottom: 12px;
}

/* Refresh button spinning animation */
.icon-spinning {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
</style>
