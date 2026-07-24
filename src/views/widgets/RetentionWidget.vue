<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<div class="retentionWidget">
		<div v-if="loading" class="retentionWidget__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent v-else-if="entries.length === 0"
			:title="t('opencatalogi', 'Nothing requires retention review')">
			<template #icon>
				<ClockOutlineIcon />
			</template>
		</NcEmptyContent>
		<div v-else class="retentionWidget__stats">
			<CnStatsBlock v-for="entry in entries"
				:key="entry.id"
				:title="entry.label"
				:count="entry.count"
				count-label=""
				horizontal
				clickable
				@click="onEntryClick(entry)" />
		</div>
	</div>
</template>

<script>
// Components
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

// Icons
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'

/**
 * RetentionWidget — dashboard widget showing retention review-queue counts.
 *
 * Reads the authenticated retention queue summary and surfaces the
 * expiring-soon / review-required / archived counts as CnStatsBlock KPI
 * entries (ADR-049 stats-block pattern — this widget is a registered
 * Nextcloud dashboard component, not a manifest-v2 `widgets[]` declaration,
 * so the blocks are composed directly). Each entry deep-links into the
 * publications listing pre-filtered to that retention status.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */
export default {
	name: 'RetentionWidget',
	components: {
		CnStatsBlock,
		NcEmptyContent,
		NcLoadingIcon,
		ClockOutlineIcon,
	},
	props: {
		title: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: false,
			summary: { expiringSoon: 0, reviewRequired: 0, archived: 0 },
		}
	},
	computed: {
		/** @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007 */
		entries() {
			const rows = []
			if (this.summary.expiringSoon > 0) {
				rows.push({
					id: 'expiring',
					label: t('opencatalogi', 'Expiring soon'),
					count: this.summary.expiringSoon,
				})
			}
			if (this.summary.reviewRequired > 0) {
				rows.push({
					id: 'review',
					label: t('opencatalogi', 'Retention review required'),
					count: this.summary.reviewRequired,
				})
			}
			if (this.summary.archived > 0) {
				rows.push({
					id: 'archived',
					label: t('opencatalogi', 'Archived'),
					count: this.summary.archived,
				})
			}
			return rows
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Open the publications listing pre-filtered to the chosen retention status.
		 * @param {object} entry - The clicked summary entry.
		 * @return {void}
		 *
		 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
		 */
		onEntryClick(entry) {
			window.open(generateUrl('/apps/opencatalogi/?retention=' + encodeURIComponent(entry.id)), '_self')
		},
		/**
		 * Fetch the retention queue summary.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
		 */
		async fetchData() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/opencatalogi/api/retention/queue'))
				this.summary = { ...this.summary, ...response.data }
			} catch (error) {
				console.error('Failed to load retention queue summary', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.retentionWidget__loading {
	display: flex;
	justify-content: center;
	padding: 1rem 0;
}

.retentionWidget__stats {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}
</style>
