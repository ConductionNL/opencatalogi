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
			<template #actions>
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

			<!-- Objects by Schema donut chart -->
			<template #widget-objects-by-schema>
				<CnChartWidget
					v-if="schemaChartData.series.length > 0"
					type="donut"
					:series="schemaChartData.series"
					:labels="schemaChartData.labels"
					:height="220"
					:options="{ legend: { position: 'bottom', fontSize: '12px' }, plotOptions: { pie: { donut: { size: '55%' } } } }" />
				<div v-else class="widget-empty">
					{{ t('opencatalogi', 'No objects found') }}
				</div>
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

			<!-- Activity graph widget (audit trail actions over time) -->
			<template #widget-activity>
				<CnChartWidget
					v-if="activityChartData.series.length > 0"
					type="area"
					:series="activityChartData.series"
					:categories="activityChartData.labels"
					:height="220"
					:options="{ stroke: { curve: 'smooth', width: 2 }, xaxis: { labels: { rotate: -45, style: { fontSize: '10px' } } }, dataLabels: { enabled: false } }" />
				<div v-else class="widget-empty">
					{{ t('opencatalogi', 'No activity data') }}
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
// eslint-disable-next-line import/named -- CnChartWidget available in local source; will be in next npm release
import { CnDashboardPage, CnStatsBlock, CnChartWidget, buildHeaders } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
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
	{ id: 1, widgetId: 'count-publications', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-concept-publications', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-concept-attachments', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'objects-by-schema', gridX: 9, gridY: 0, gridWidth: 3, gridHeight: 5 },
	{ id: 5, widgetId: 'activity', gridX: 0, gridY: 2, gridWidth: 9, gridHeight: 4 },
	{ id: 6, widgetId: 'concept-publications', gridX: 0, gridY: 6, gridWidth: 6, gridHeight: 4 },
	{ id: 7, widgetId: 'concept-attachments', gridX: 6, gridY: 6, gridWidth: 6, gridHeight: 4 },
]

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		CnDashboardPage,
		CnStatsBlock,
		CnChartWidget,
		Plus,
		Refresh,
		FileDocumentEditOutline,
		Paperclip,
	},
	data() {
		return {
			// Icon components for CnStatsBlock :icon prop
			DatabaseEyeOutline,
			FileDocumentEditOutline,
			PaperclipOff,
			globalLoading: false,
			error: null,
			refreshTimer: null,
			dashboardLayout: [...DEFAULT_LAYOUT],
			schemaChartData: { labels: [], series: [] },
			activityChartData: { labels: [], series: [] },
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
			return this.allPublications.filter((p) => this.isConcept(p))
		},
		// TODO: Attachments in OpenCatalogi are per-publication files (see
		// catalogStore.getPublicationAttachments) rather than a queryable
		// collection. Wire up a real source later — either aggregate
		// per-publication attachments or add a backend endpoint that returns
		// concept attachments across all publications. For now this returns
		// [] so the widgets show 0.
		allAttachments() {
			return []
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
				{ id: 'count-publications', title: t('opencatalogi', 'Publications'), type: 'custom' },
				{ id: 'count-concept-publications', title: t('opencatalogi', 'Concept Publications'), type: 'custom' },
				{ id: 'count-concept-attachments', title: t('opencatalogi', 'Concept Attachments'), type: 'custom' },
				{ id: 'objects-by-schema', title: t('opencatalogi', 'Objects by Type'), type: 'custom' },
				{ id: 'activity', title: t('opencatalogi', 'Activity'), type: 'custom' },
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
					this.fetchSchemaChart(),
					this.fetchActivityChart(),
				])
			} catch (err) {
				this.error = err.message || t('opencatalogi', 'Failed to load dashboard data')
				console.error('Dashboard fetch error:', err)
			} finally {
				this.globalLoading = false
			}
		},

		async fetchSchemaChart() {
			try {
				const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
				const response = await fetch(
					`${prefix}/apps/openregister/api/dashboard/charts/objects-by-schema`,
					{ method: 'GET', headers: buildHeaders() },
				)
				if (response.ok) {
					const data = await response.json()
					this.schemaChartData = {
						labels: data.labels || [],
						series: data.series || [],
					}
				}
			} catch (err) {
				console.warn('Failed to load schema chart:', err)
			}
		},

		async fetchActivityChart() {
			try {
				const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
				const response = await fetch(
					`${prefix}/apps/openregister/api/dashboard/charts/audit-trail-actions`,
					{ method: 'GET', headers: buildHeaders() },
				)
				if (response.ok) {
					const data = await response.json()
					this.activityChartData = {
						labels: data.labels || [],
						series: data.series || [],
					}
				}
			} catch (err) {
				console.warn('Failed to load activity chart:', err)
			}
		},

		normalizeDate(value) {
			if (value == null || value === '') return null
			return String(value).slice(0, 10)
		},

		isConcept(obj) {
			return !this.normalizeDate(obj?.publicatiedatum)
				&& !this.normalizeDate(obj?.depublicatiedatum)
		},

		createPublication() {
			objectStore.clearActiveObject('publication')
			navigationStore.setModal('viewObject')
		},

		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},

	},
}
</script>

<style scoped>
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
