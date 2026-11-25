<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="woo-annual-report" data-testid="woo-annual-report">
		<NcLoadingIcon v-if="loading" :size="20" />
		<NcNoteCard v-else-if="!available" type="info" data-testid="woo-annual-report-unavailable">
			<p>{{ t('opencatalogi', 'The Woo annual report needs a newer OpenRegister.') }}</p>
		</NcNoteCard>
		<template v-else>
			<p>
				{{ t('opencatalogi', 'The published figures of a year beside the year before. Woo requests are reported in dossiq.') }}
			</p>
			<div class="woo-annual-report__fields">
				<NcSelect
					v-model="year"
					:options="years"
					:clearable="false"
					:inputLabel="t('opencatalogi', 'Year')"
					:disabled="generating" />
				<NcSelect
					v-model="format"
					:options="formats"
					:clearable="false"
					:inputLabel="t('opencatalogi', 'Format')"
					:disabled="generating" />
			</div>
			<NcButton
				variant="primary"
				:disabled="generating"
				data-testid="woo-annual-report-generate"
				@click="generate">
				<template #icon>
					<NcLoadingIcon v-if="generating" :size="20" />
				</template>
				{{ t('opencatalogi', 'Generate annual report') }}
			</NcButton>
			<NcNoteCard v-if="failed" type="error" data-testid="woo-annual-report-failed">
				<p>{{ t('opencatalogi', 'The annual report could not be generated.') }}</p>
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { fetchAnnualReportAvailability, generateAnnualReport, reportYears } from '../../services/wooAnnualReport.js'

/**
 * WooAnnualReportWidget: Generate annual report on the publications report,
 * offered only when OpenRegister can generate it, else saying why (REQ-WAR-002).
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */
export default {
	name: 'WooAnnualReportWidget',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcSelect },
	inheritAttrs: false,

	data() {
		const years = reportYears()
		return {
			loading: true,
			available: false,
			templateId: null,
			formats: [],
			years,
			year: years[0],
			format: 'pdf',
			generating: false,
			failed: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Ask whether the report can be generated.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
		 */
		async load() {
			this.loading = true
			try {
				const availability = await fetchAnnualReportAvailability()
				this.available = availability.available
				this.templateId = availability.templateId
				this.formats = availability.formats
				this.format = availability.formats[0] || 'pdf'
			} catch {
				this.available = false
			} finally {
				this.loading = false
			}
		},

		/**
		 * Generate the chosen year in the chosen format.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
		 */
		async generate() {
			this.generating = true
			this.failed = false
			try {
				await generateAnnualReport({ templateId: this.templateId, year: this.year, format: this.format })
			} catch {
				this.failed = true
			} finally {
				this.generating = false
			}
		},
	},
}
</script>

<style scoped>
.woo-annual-report {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.woo-annual-report__fields {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
