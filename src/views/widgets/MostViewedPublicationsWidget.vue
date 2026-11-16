<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div data-testid="most-viewed-widget">
		<div class="mostViewedControls">
			<NcSelect
				v-model="period"
				inputLabel=""
				:aria-label-combobox="t('opencatalogi', 'Period')"
				:options="periodOptions"
				:reduce="(option) => option.value"
				label="label"
				@update:modelValue="load" />
		</div>
		<CnDataTable
			:rows="items"
			:columns="columns"
			:loading="loading"
			hideHeader
			borderless
			@rowClick="onRowClick">
			<template #empty>
				<NcEmptyContent :title="t('opencatalogi', 'No usage measured yet')">
					<template #icon>
						<ChartLine />
					</template>
				</NcEmptyContent>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcSelect } from '@nextcloud/vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import { fetchMostViewed, mostViewedRows, publicationPageUrl } from '../../services/usageStats.js'
import { LIST_COLUMNS, navigateTo } from './widgetTable.js'

/**
 * MostViewedPublicationsWidget — top-N most-viewed publications for a period.
 *
 * The NcSelect period picker (7/30/90 days) sits above the list and drives
 * the fetch; the list itself renders the universal CnDataTable list-widget
 * pattern (ADR-049). It covers every catalogue the officer can see, lists each
 * publication by title, and clicking an entry opens that publication.
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.3
 */
export default {
	name: 'MostViewedPublicationsWidget',
	components: {
		CnDataTable,
		NcEmptyContent,
		NcSelect,
		ChartLine,
	},

	props: {
		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			period: 30,
			topViewed: [],
			columns: LIST_COLUMNS,
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
			return mostViewedRows(this.topViewed, this.t)
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
				this.topViewed = await fetchMostViewed({ from: this.rangeFrom(), top: 10 })
			} catch (e) {
				this.topViewed = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param row
		 * @spec openspec/specs/publication-usage-analytics/spec.md
		 */
		onRowClick(row) {
			navigateTo(publicationPageUrl(row))
		},
	},
}
</script>

<style scoped>
.mostViewedControls {
	margin-bottom: 0.5rem;
}
</style>
