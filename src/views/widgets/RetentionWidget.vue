<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('opencatalogi', 'Nothing requires retention review')">
				<template #icon>
					<ClockOutlineIcon />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
// Components
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

// Icons
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'

/**
 * RetentionWidget — dashboard widget showing retention review-queue counts.
 *
 * Reads the authenticated retention queue summary and surfaces the
 * expiring-soon / review-required / archived counts, each deep-linking into the
 * publications listing pre-filtered to that retention status.
 *
 * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */
export default {
	name: 'RetentionWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
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
		/** @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007 */
		items() {
			const rows = []
			if (this.summary.expiringSoon > 0) {
				rows.push({
					id: 'expiring',
					mainText: t('opencatalogi', 'Expiring soon'),
					subText: String(this.summary.expiringSoon),
				})
			}
			if (this.summary.reviewRequired > 0) {
				rows.push({
					id: 'review',
					mainText: t('opencatalogi', 'Retention review required'),
					subText: String(this.summary.reviewRequired),
				})
			}
			if (this.summary.archived > 0) {
				rows.push({
					id: 'archived',
					mainText: t('opencatalogi', 'Archived'),
					subText: String(this.summary.archived),
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
		 * @param {object} item - The clicked summary row.
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
		 */
		onShow(item) {
			window.open(generateUrl('/apps/opencatalogi/?retention=' + encodeURIComponent(item.id)), '_self')
		},
		/**
		 * Fetch the retention queue summary.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
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
