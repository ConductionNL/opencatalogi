<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="usageStatsPanel" data-testid="usage-stats-panel">
		<NcLoadingIcon v-if="loading" :size="32" />
		<div v-else>
			<div class="usageStatsTotals">
				<div class="usageStatCard" data-testid="usage-stat-views">
					<span class="usageStatLabel">{{ t('opencatalogi', 'Views') }}</span>
					<span class="usageStatValue">{{ formatCount(stats.views) }}</span>
					<span class="usageStatTrend">{{ trendLabel(trendViews) }}</span>
				</div>
				<div class="usageStatCard" data-testid="usage-stat-downloads">
					<span class="usageStatLabel">{{ t('opencatalogi', 'Downloads') }}</span>
					<span class="usageStatValue">{{ formatCount(stats.downloads) }}</span>
					<span class="usageStatTrend">{{ trendLabel(trendDownloads) }}</span>
				</div>
			</div>
			<NcNoteCard type="info" data-testid="usage-counting-start">
				<p>{{ countingStartText }}</p>
				<p>{{ t('opencatalogi', 'Counts are requests, not unique visitors.') }}</p>
			</NcNoteCard>
			<table v-if="stats.series && stats.series.length" class="usageStatsTable">
				<thead>
					<tr>
						<th>{{ t('opencatalogi', 'Date') }}</th>
						<th>{{ t('opencatalogi', 'Views') }}</th>
						<th>{{ t('opencatalogi', 'Downloads') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in stats.series" :key="row.date">
						<td>{{ row.date }}</td>
						<td>{{ formatCount(row.views) }}</td>
						<td>{{ formatCount(row.downloads) }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	fetchPublicationStats,
	formatCount,
	deriveTrend,
	countingStartNote,
} from '../../services/usageStats.js'

/**
 * PublicationStatsPanel — privacy-safe per-publication reach panel.
 *
 * Renders view/download totals, a simple recent-trend indicator, the daily
 * series, and the counting-start note. Pure presentation; all aggregation is
 * server-side. Counts are requests, not unique visitors — stated in the panel.
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
export default {
	name: 'PublicationStatsPanel',
	components: {
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		publicationId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: true,
			stats: { views: 0, downloads: 0, series: [], countingStart: null },
		}
	},
	computed: {
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		trendViews() {
			return deriveTrend(this.stats.series, 'views')
		},
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		trendDownloads() {
			return deriveTrend(this.stats.series, 'downloads')
		},
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		countingStartText() {
			return countingStartNote(this.stats.countingStart, this.t)
		},
	},
	watch: {
		publicationId: 'loadStats',
	},
	mounted() {
		this.loadStats()
	},
	methods: {
		formatCount,
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		trendLabel(trend) {
			if (trend === 'up') return this.t('opencatalogi', 'Trending up')
			if (trend === 'down') return this.t('opencatalogi', 'Trending down')
			return this.t('opencatalogi', 'Stable')
		},
		/** @spec openspec/specs/publication-usage-analytics/spec.md */
		async loadStats() {
			if (!this.publicationId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				this.stats = await fetchPublicationStats(this.publicationId)
			} catch (e) {
				this.stats = { views: 0, downloads: 0, series: [], countingStart: null }
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.usageStatsTotals {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: 1rem;
}
.usageStatCard {
	display: flex;
	flex-direction: column;
	padding: 1rem;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
	min-width: 140px;
}
.usageStatLabel {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}
.usageStatValue {
	font-size: 1.6rem;
	font-weight: 600;
}
.usageStatTrend {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}
.usageStatsTable {
	width: 100%;
	border-collapse: collapse;
	margin-top: 1rem;
}
.usageStatsTable th,
.usageStatsTable td {
	text-align: left;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
