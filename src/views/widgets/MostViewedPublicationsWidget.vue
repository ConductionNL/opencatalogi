<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div data-testid="most-viewed-widget">
		<div class="mostViewedControls">
			<NcSelect v-model="period"
				input-label=""
				:aria-label-combobox="t('opencatalogi', 'Period')"
				:options="periodOptions"
				:reduce="option => option.value"
				label="label"
				@input="load" />
		</div>
		<NcDashboardWidget :items="items"
			:loading="loading"
			@show="onShow">
			<template #empty-content>
				<NcEmptyContent :title="t('opencatalogi', 'No usage measured yet')">
					<template #icon>
						<ChartLine />
					</template>
				</NcEmptyContent>
			</template>
		</NcDashboardWidget>
	</div>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent, NcSelect } from '@nextcloud/vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import { fetchCatalogStats, formatCount } from '../../services/usageStats.js'

/**
 * MostViewedPublicationsWidget — top-N most-viewed publications for a period.
 *
 * Built on the existing NcDashboardWidget surface. Lists the top publications
 * by privacy-safe view counts for the selected period; clicking an entry deep
 * links to that publication.
 *
 * @spec openspec/changes/publication-usage-analytics/specs/publication-usage-analytics/spec.md
 */
export default {
	name: 'MostViewedPublicationsWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		NcSelect,
		ChartLine,
	},
	props: {
		title: {
			type: String,
			default: '',
		},
		catalogSlug: {
			type: String,
			default: 'publications',
		},
	},
	data() {
		return {
			loading: true,
			period: 30,
			topViewed: [],
		}
	},
	computed: {
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		periodOptions() {
			return [
				{ value: 7, label: this.t('opencatalogi', 'Last 7 days') },
				{ value: 30, label: this.t('opencatalogi', 'Last 30 days') },
				{ value: 90, label: this.t('opencatalogi', 'Last 90 days') },
			]
		},
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		items() {
			return this.topViewed.map((entry) => ({
				id: entry.publication,
				mainText: entry.publication,
				subText: this.t('opencatalogi', '{count} views', { count: formatCount(entry.views) }),
			}))
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		rangeFrom() {
			const d = new Date()
			d.setDate(d.getDate() - this.period)
			return d.toISOString().slice(0, 10)
		},
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		async load() {
			this.loading = true
			try {
				const stats = await fetchCatalogStats(this.catalogSlug, { from: this.rangeFrom() })
				this.topViewed = Array.isArray(stats.topViewed) ? stats.topViewed : []
			} catch (e) {
				this.topViewed = []
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		onShow(item) {
			window.location.href = `/index.php/apps/opencatalogi/publications/${item.id}`
		},
	},
}
</script>

<style scoped>
.mostViewedControls {
	margin-bottom: 0.5rem;
}
</style>
