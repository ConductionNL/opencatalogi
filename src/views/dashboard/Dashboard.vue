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

			<!-- Publications by Category donut chart -->
			<template #widget-publications-by-category>
				<CnChartWidget
					v-if="publicationsByCategoryData.series.length > 0"
					type="donut"
					:series="publicationsByCategoryData.series"
					:labels="publicationsByCategoryData.labels"
					:height="360"
					:options="{
						colors: ['#0082C9', '#059669', '#D97706', '#DC2626', '#7C3AED', '#0891B2', '#DB2777'],
						legend: { position: 'bottom', fontSize: '13px', itemMargin: { horizontal: 8, vertical: 4 } },
						plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: t('opencatalogi', 'Total'), fontSize: '14px', fontWeight: 600 }, value: { fontSize: '28px', fontWeight: 700 } } } } },
						dataLabels: { enabled: false },
						tooltip: { y: { formatter: (val) => val + ' ' + t('opencatalogi', 'publications') } }
					}" />
				<div v-else class="widget-empty">
					<DatabaseEyeOutline :size="40" class="widget-empty-icon" />
					<p>{{ t('opencatalogi', 'No publications found') }}</p>
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
					:clickable="true"
					@click="navigateToPublications()" />
			</template>

			<!-- Concept Publications count widget -->
			<template #widget-count-concept-publications>
				<CnStatsBlock
					:title="t('opencatalogi', 'Concept Publications')"
					:count="kpis.conceptPublicationCount"
					:count-label="t('opencatalogi', 'concept')"
					:icon="FileDocumentEditOutline"
					variant="warning"
					horizontal
					:clickable="true"
					@click="navigateToPublications()" />
			</template>

			<!-- Published Publications count widget -->
			<template #widget-count-published-publications>
				<CnStatsBlock
					:title="t('opencatalogi', 'Published')"
					:count="kpis.publishedPublicationCount"
					:count-label="t('opencatalogi', 'published')"
					:icon="FileDocumentCheckOutline"
					variant="success"
					horizontal
					:clickable="true"
					@click="navigateToPublications()" />
			</template>

			<!-- Depublished Publications count widget -->
			<template #widget-count-depublished-publications>
				<CnStatsBlock
					:title="t('opencatalogi', 'Depublished')"
					:count="kpis.depublishedPublicationCount"
					:count-label="t('opencatalogi', 'depublished')"
					:icon="AlertOutline"
					variant="error"
					horizontal
					:clickable="true"
					@click="navigateToPublications()" />
			</template>

			<!-- Concept Attachments count widget -->
			<!-- TODO: Re-add concept attachments widget once a scalable fetch strategy is in place.
			     Fetching files per-publication does not scale for large catalogs.
			     Do NOT remove this code.
			<template #widget-count-concept-attachments>
				<CnStatsBlock
					:title="t('opencatalogi', 'Concept Attachments')"
					:count="kpis.conceptAttachmentCount"
					:count-label="t('opencatalogi', 'concept')"
					:icon="PaperclipOff"
					:variant="kpis.conceptAttachmentCount > 0 ? 'warning' : 'default'"
					horizontal />
			</template>
			-->

			<!-- Activity graph widget (audit trail actions over time) -->
			<template #widget-activity>
				<CnChartWidget
					v-if="activityChartData.series.length > 0"
					type="area"
					:series="activityChartData.series"
					:categories="activityChartData.labels"
					:height="280"
					:options="{
						stroke: { curve: 'smooth', width: 2 },
						fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100] } },
						xaxis: { labels: { rotate: -30, style: { fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
						yaxis: { labels: { style: { fontSize: '11px' } } },
						grid: { borderColor: 'var(--color-border, #e0e0e0)', strokeDashArray: 4 },
						dataLabels: { enabled: false },
						tooltip: { shared: true, intersect: false }
					}" />
				<div v-else class="widget-empty">
					<ChartAreaspline :size="40" class="widget-empty-icon" />
					<p>{{ t('opencatalogi', 'No activity recorded yet') }}</p>
				</div>
			</template>

			<!-- Traffic graph widget (API read requests over time) -->
			<template #widget-traffic>
				<CnChartWidget
					v-if="trafficChartData.series.length > 0"
					type="area"
					:series="trafficChartData.series"
					:categories="trafficChartData.labels"
					:height="280"
					:options="{
						stroke: { curve: 'smooth', width: 2 },
						colors: ['#079cff'],
						fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100] } },
						xaxis: { labels: { rotate: -30, style: { fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
						yaxis: { labels: { style: { fontSize: '11px' } } },
						grid: { borderColor: 'var(--color-border, #e0e0e0)', strokeDashArray: 4 },
						dataLabels: { enabled: false },
						tooltip: { shared: true, intersect: false }
					}" />
				<div v-else class="widget-empty">
					<ChartAreaspline :size="40" class="widget-empty-icon" />
					<p>{{ t('opencatalogi', 'No traffic data') }}</p>
				</div>
			</template>

			<!-- Popular Search Terms widget -->
			<template #widget-popular-searches>
				<div class="search-terms-content">
					<div v-if="popularSearchTerms.length === 0" class="widget-empty">
						{{ t('opencatalogi', 'No search data yet') }}
					</div>
					<div v-else class="search-terms-list">
						<div
							v-for="(term, index) in popularSearchTerms"
							:key="term.term || index"
							class="search-term-item">
							<span class="search-term-rank">{{ index + 1 }}</span>
							<span class="search-term-text">{{ term.term }}</span>
							<span class="search-term-count">{{ term.count }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- Concept Publications widget -->
			<template #widget-concept-publications-title-icon>
				<FileDocumentEditOutline :size="20" />
			</template>
			<template #widget-concept-publications>
				<div class="concept-widget-content" tabindex="0">
					<div v-if="conceptPublications.length === 0" class="widget-empty">
						{{ t('opencatalogi', 'No concept publications') }}
					</div>
					<div v-else class="concept-list">
						<div
							v-for="publication in conceptPublications"
							:key="publication.id"
							class="concept-item concept-item-clickable"
							@click="openPublication(publication)">
							<div class="concept-item-content">
								<span class="concept-item-title">{{ publication.title || publication.name || publication.titel || publication.naam || publication.id }}</span>
								<span class="concept-item-schema">{{ resolveSchemaName(publication) }}</span>
							</div>
							<span class="concept-item-status">{{ t('opencatalogi', 'Concept') }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- Concept Attachments widget -->
			<!-- TODO: Re-add concept attachments widget once a scalable fetch strategy is in place.
			     Fetching files per-publication does not scale for large catalogs.
			     Do NOT remove this code.
			<template #widget-concept-attachments>
				<div class="concept-widget-content" tabindex="0">
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
			-->

			<!-- Published Publications widget -->
			<template #widget-published-publications-title-icon>
				<FileDocumentCheckOutline :size="20" />
			</template>
			<template #widget-published-publications>
				<div class="concept-widget-content" tabindex="0">
					<div v-if="publishedPublications.length === 0" class="widget-empty">
						{{ t('opencatalogi', 'No published publications') }}
					</div>
					<div v-else class="concept-list">
						<div
							v-for="publication in publishedPublications"
							:key="publication.id"
							class="concept-item concept-item-clickable"
							@click="openPublication(publication)">
							<div class="concept-item-content">
								<span class="concept-item-title">{{ publication.title || publication.name || publication.titel || publication.naam || publication.id }}</span>
								<span class="concept-item-schema">{{ resolveSchemaName(publication) }}</span>
							</div>
							<span class="published-item-status">{{ t('opencatalogi', 'Published') }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- Depublished Publications widget -->
			<template #widget-depublished-publications-title-icon>
				<AlertOutline :size="20" />
			</template>
			<template #widget-depublished-publications>
				<div class="concept-widget-content" tabindex="0">
					<div v-if="depublishedPublications.length === 0" class="widget-empty">
						{{ t('opencatalogi', 'No depublished publications') }}
					</div>
					<div v-else class="concept-list">
						<div
							v-for="publication in depublishedPublications"
							:key="publication.id"
							class="concept-item concept-item-clickable"
							@click="openPublication(publication)">
							<div class="concept-item-content">
								<span class="concept-item-title">{{ publication.title || publication.name || publication.titel || publication.naam || publication.id }}</span>
								<span class="concept-item-schema">{{ resolveSchemaName(publication) }}</span>
							</div>
							<span class="depublished-item-status">{{ t('opencatalogi', 'Depublished') }}</span>
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
import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ChartAreaspline from 'vue-material-design-icons/ChartAreaspline.vue'
// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
// import Paperclip from 'vue-material-design-icons/Paperclip.vue'
// import PaperclipOff from 'vue-material-design-icons/PaperclipOff.vue'
import { objectStore, navigationStore } from '../../store/store.js'
import { isPublished, isDepublished, isConcept } from '../../services/publicationStatus.js'

/**
 * Default dashboard layout:
 * - 2×2 KPI grid (left half, rows 0-5)
 * - Publications by category donut (right half, rows 0-5)
 * - Activity chart (full width, rows 6-10)
 * - Three publication lists (each 4 cols, rows 11-15)
 */
const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'count-publications', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 3, showTitle: false },
	{ id: 2, widgetId: 'count-concept-publications', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 3, showTitle: false },
	{ id: 3, widgetId: 'count-published-publications', gridX: 0, gridY: 3, gridWidth: 3, gridHeight: 3, showTitle: false },
	{ id: 9, widgetId: 'count-depublished-publications', gridX: 3, gridY: 3, gridWidth: 3, gridHeight: 3, showTitle: false },
	// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
	// { id: x, widgetId: 'count-concept-attachments', ... },
	{ id: 4, widgetId: 'publications-by-category', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 6 },
	{ id: 5, widgetId: 'activity', gridX: 0, gridY: 6, gridWidth: 12, gridHeight: 5 },
	{ id: 11, widgetId: 'traffic', gridX: 0, gridY: 11, gridWidth: 8, gridHeight: 5 },
	{ id: 12, widgetId: 'popular-searches', gridX: 8, gridY: 11, gridWidth: 4, gridHeight: 5 },
	{ id: 6, widgetId: 'concept-publications', gridX: 0, gridY: 16, gridWidth: 4, gridHeight: 5 },
	{ id: 8, widgetId: 'published-publications', gridX: 4, gridY: 16, gridWidth: 4, gridHeight: 5 },
	{ id: 10, widgetId: 'depublished-publications', gridX: 8, gridY: 16, gridWidth: 4, gridHeight: 5 },
	// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
	// { id: 7, widgetId: 'concept-attachments', ... },
]

/**
 * Dashboard — overview view: catalogs, publication totals, activity chart.
 *
 * @spec openspec/changes/retrofit-2026-05-25-dashboard/tasks.md#task-2
 */
export default {
	name: 'Dashboard',
	components: {
		NcButton,
		CnDashboardPage,
		CnStatsBlock,
		CnChartWidget,
		Plus,
		Refresh,
		DatabaseEyeOutline,
		FileDocumentEditOutline,
		FileDocumentCheckOutline,
		AlertOutline,
		ChartAreaspline,
		// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
		// Paperclip,
	},
	data() {
		return {
			// Icon components for CnStatsBlock :icon prop
			DatabaseEyeOutline,
			FileDocumentEditOutline,
			FileDocumentCheckOutline,
			AlertOutline,
			// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
			// PaperclipOff,
			globalLoading: false,
			error: null,
			refreshTimer: null,
			dashboardLayout: [...DEFAULT_LAYOUT],
			activityChartData: { labels: [], series: [] },
			trafficChartData: { labels: [], series: [] },
			popularSearchTerms: [],
			publicationTotal: 0,
			// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
			// attachmentsList: [],
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		catalogs() {
			return objectStore.getCollection('catalog').results || []
		},
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		allPublications() {
			return objectStore.getCollection('publication').results || []
		},
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		conceptPublications() {
			return this.allPublications.filter((p) => isConcept(p))
		},
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		publishedPublications() {
			return this.allPublications.filter((p) => isPublished(p))
		},
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		depublishedPublications() {
			return this.allPublications.filter((p) => isDepublished(p))
		},
		// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
		// allAttachments() { return this.attachmentsList },
		// conceptAttachments() {
		//   return this.allAttachments.filter((attachment) => attachment.status === 'Concept')
		// },
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		kpis() {
			return {
				catalogCount: this.catalogs.length,
				publicationCount: this.publicationTotal || this.allPublications.length,
				conceptPublicationCount: this.conceptPublications.length,
				publishedPublicationCount: this.publishedPublications.length,
				depublishedPublicationCount: this.depublishedPublications.length,
				// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
				// conceptAttachmentCount: this.conceptAttachments.length,
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		publicationsByCategoryData() {
			const counts = {}
			for (const pub of this.allPublications) {
				const schemaRef = pub['@self']?.schema
				let name
				if (typeof schemaRef === 'object' && schemaRef) {
					name = schemaRef.title || schemaRef.name || t('opencatalogi', 'Unknown')
				} else if (schemaRef) {
					const match = objectStore.availableSchemas.find(s => Number(s.id) === Number(schemaRef))
					name = match?.title || match?.name || String(schemaRef)
				} else {
					name = t('opencatalogi', 'Unknown')
				}
				counts[name] = (counts[name] || 0) + 1
			}
			return {
				labels: Object.keys(counts),
				series: Object.values(counts),
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		hasData() {
			return this.catalogs.length > 0
				|| this.allPublications.length > 0
		},
		/** First catalog slug available in the store, used for Publications route navigation. */
		firstCatalogSlug() {
			return this.catalogs[0]?.slug || null
		},
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		widgetDefs() {
			return [
				{ id: 'count-publications', title: t('opencatalogi', 'Publications'), type: 'custom' },
				{ id: 'count-concept-publications', title: t('opencatalogi', 'Concept Publications'), type: 'custom' },
				{ id: 'count-published-publications', title: t('opencatalogi', 'Published Publications'), type: 'custom' },
				{ id: 'count-depublished-publications', title: t('opencatalogi', 'Depublished Publications'), type: 'custom' },
				// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
				// { id: 'count-concept-attachments', title: t('opencatalogi', 'Concept Attachments'), type: 'custom' },
				{ id: 'publications-by-category', title: t('opencatalogi', 'Publications by Category'), type: 'custom' },
				{ id: 'activity', title: t('opencatalogi', 'Activity'), type: 'custom' },
				{ id: 'traffic', title: t('opencatalogi', 'Traffic'), type: 'custom' },
				{ id: 'popular-searches', title: t('opencatalogi', 'Popular Search Terms'), type: 'custom' },
				{ id: 'concept-publications', title: t('opencatalogi', 'Concept Publications'), type: 'custom', titleIconPosition: 'left', titleIconColor: 'var(--color-warning)' },
				{ id: 'published-publications', title: t('opencatalogi', 'Published Publications'), type: 'custom', titleIconPosition: 'left', titleIconColor: 'var(--color-success)' },
				{ id: 'depublished-publications', title: t('opencatalogi', 'Depublished Publications'), type: 'custom', titleIconPosition: 'left', titleIconColor: 'var(--color-error)' },
				// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
				// { id: 'concept-attachments', title: t('opencatalogi', 'Concept Attachments'), type: 'custom' },
			]
		},
	},
	/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
	async mounted() {
		await this.loadDashboardData()
		this.refreshTimer = setInterval(() => {
			this.loadDashboardData()
		}, 5 * 60 * 1000)
	},
	/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
	beforeDestroy() {
		if (this.refreshTimer) {
			clearInterval(this.refreshTimer)
			this.refreshTimer = null
		}
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		async loadDashboardData() {
			this.globalLoading = true
			this.error = null

			try {
				await Promise.allSettled([
					objectStore.fetchCollection('catalog'),
					this.fetchAllPublications(),
					this.fetchActivityChart(),
					this.fetchTrafficChart(),
					this.fetchPopularSearchTerms(),
				])
			} catch (err) {
				this.error = err.message || t('opencatalogi', 'Failed to load dashboard data')
				console.error('Dashboard fetch error:', err)
			} finally {
				this.globalLoading = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		async fetchAllPublications() {
			try {
				const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
				const response = await fetch(
					`${prefix}/apps/opencatalogi/api/publications?_page=1&_limit=1000&_extend=@self.schema,@self.register`,
					{ method: 'GET', headers: buildHeaders() },
				)
				if (response.ok) {
					const data = await response.json()
					this.publicationTotal = data.total || 0
					objectStore.setCollection('publication', data.results || [])
				}
			} catch (err) {
				console.warn('Failed to load publications:', err)
			}
		},

		// TODO: Re-add when concept attachments widget is restored. Do NOT remove.
		// fetchConceptAttachments fetches files for every publication and filters by status === 'Concept'.
		// Disabled because it issues one HTTP request per publication and does not scale.
		// async fetchConceptAttachments() {
		//   const publications = objectStore.getCollection('publication').results || []
		//   const withFiles = publications.filter((p) => { const c = p['@self']?.files; return c === undefined || c === null || c > 0 })
		//   if (withFiles.length === 0) return
		//   const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
		//   const results = await Promise.allSettled(withFiles.map(async (pub) => {
		//     const register = pub['@self']?.register; const schema = pub['@self']?.schema; const id = pub.id || pub['@self']?.id
		//     if (!register || !schema || !id) return []
		//     const registerId = typeof register === 'object' ? register?.id || register?.uuid : register
		//     const schemaId = typeof schema === 'object' ? schema?.id || schema?.uuid : schema
		//     const response = await fetch(`${prefix}/apps/openregister/api/objects/${registerId}/${schemaId}/${id}/files`, { headers: buildHeaders() })
		//     if (!response.ok) return []
		//     const data = await response.json(); return data.results || []
		//   }))
		//   this.attachmentsList = results.filter((r) => r.status === 'fulfilled').flatMap((r) => r.value)
		// },

		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
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
						series: (data.series || []).map((s) => ({ ...s, name: t('opencatalogi', s.name) })),
					}
				}
			} catch (err) {
				console.warn('Failed to load activity chart:', err)
			}
		},

		/**
		 * Fetch API read-request volume over time (traffic graph).
		 * Filters the audit-trail-actions chart to the "Read" series.
		 * @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1
		 */
		async fetchTrafficChart() {
			try {
				const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
				const response = await fetch(
					`${prefix}/apps/openregister/api/dashboard/charts/audit-trail-actions`,
					{ method: 'GET', headers: buildHeaders() },
				)
				if (response.ok) {
					const data = await response.json()
					const allSeries = data.series || []
					const readSeries = allSeries.filter((s) => s.name === 'Read')
					this.trafficChartData = {
						labels: data.labels || [],
						series: readSeries.length > 0
							? readSeries.map((s) => ({ ...s, name: t('opencatalogi', 'Requests') }))
							: allSeries.map((s) => ({ ...s, name: t('opencatalogi', s.name) })),
					}
				}
			} catch (err) {
				console.warn('Failed to load traffic chart:', err)
			}
		},

		/**
		 * Fetch the most popular search terms from OpenRegister search trails.
		 * @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1
		 */
		async fetchPopularSearchTerms() {
			try {
				const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
				const response = await fetch(
					`${prefix}/apps/openregister/api/search-trails/popular-terms?limit=10`,
					{ method: 'GET', headers: buildHeaders() },
				)
				if (response.ok) {
					const data = await response.json()
					this.popularSearchTerms = (data.results || []).map((item) => ({
						term: item.search_term || item.term || item.query || '-',
						count: item.count || item.total || 0,
					}))
				}
			} catch (err) {
				console.warn('Failed to load popular search terms:', err)
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		resolveSchemaName(publication) {
			const schemaRef = publication['@self']?.schema
			if (!schemaRef) return ''
			if (typeof schemaRef === 'object') {
				return schemaRef.title || schemaRef.name || ''
			}
			const match = objectStore.availableSchemas.find(s => Number(s.id) === Number(schemaRef))
			return match?.title || match?.name || ''
		},

		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		createPublication() {
			objectStore.clearActiveObject('publication')
			navigationStore.setModal('viewObject')
		},

		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		openPublication(publication) {
			const catalogSlug = this.catalogSlugById(publication?.catalog)
			const pubId = publication?.id || publication?.['@self']?.id
			if (catalogSlug && pubId) {
				this.$router.push({ name: 'PublicationDetail', params: { catalogSlug, id: String(pubId) } })
			} else if (catalogSlug) {
				this.$router.push({ name: 'Publications', params: { catalogSlug } })
			} else {
				this.$router.push({ name: 'Catalogs' })
			}
		},

		/**
		 * Navigate to the Publications index for the first available catalog,
		 * or fall back to the Catalogs list if no catalog slug is known yet.
		 * Status-based filtering is intentionally omitted: publication status is
		 * derived from date fields (publicatiedatum / depublicatiedatum), not a
		 * simple status query parameter.
		 */
		navigateToPublications() {
			if (this.firstCatalogSlug) {
				this.$router.push({ name: 'Publications', params: { catalogSlug: this.firstCatalogSlug } })
			} else {
				this.$router.push({ name: 'Catalogs' })
			}
		},

		/**
		 * Resolve a catalog slug from a catalog ID reference on a publication.
		 * @param {string|number|object|null} catalogRef - catalog field value from a publication
		 * @return {string|null}
		 */
		catalogSlugById(catalogRef) {
			if (!catalogRef) return this.firstCatalogSlug
			const refId = typeof catalogRef === 'object' ? (catalogRef?.id ?? catalogRef?.slug) : catalogRef
			const found = this.catalogs.find(
				(c) => String(c.id) === String(refId) || c.slug === String(refId),
			)
			return found?.slug || this.firstCatalogSlug
		},

		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-1 */
		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},

	},
}
</script>

<style scoped>
/* Search terms widget */
.search-terms-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.search-terms-list {
	display: flex;
	flex-direction: column;
}

.search-term-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border-dark, rgba(0, 0, 0, 0.07));
}

.search-term-rank {
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	min-width: 20px;
	text-align: center;
}

.search-term-text {
	flex: 1;
	font-size: 14px;
}

.search-term-count {
	font-weight: 600;
	color: var(--color-primary-element);
	font-size: 13px;
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

.concept-item-clickable,
.concept-item-clickable * {
	cursor: pointer;
}

.concept-item-clickable:hover {
	background: var(--color-background-hover);
	border-radius: 6px;
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

.concept-item-schema {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-primary-element, #0082c9);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-top: 1px;
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

.published-item-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-success-hover, rgba(233, 163, 0, 0.1));
	color: var(--color-success-text, #7a5700);
	flex-shrink: 0;
}

.depublished-item-icon {
	color: var(--color-error);
	flex-shrink: 0;
}

.depublished-item-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-error-hover, rgba(211, 47, 47, 0.1));
	color: var(--color-error-text, #7a1515);
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
