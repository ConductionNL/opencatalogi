<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="donl-harvest-status" data-testid="donl-harvest-status">
		<h3 class="donl-harvest-status__heading">
			{{ t('opencatalogi', 'data.overheid.nl registration') }}
		</h3>
		<p class="donl-harvest-status__hint" data-testid="donl-registration-status">
			{{ statusLabel }}
		</p>
		<p class="donl-harvest-status__hint" data-testid="donl-harvest-evidence">
			{{ harvestLabel }}
		</p>
		<p v-if="countLabel" class="donl-harvest-status__hint" data-testid="donl-harvest-count">
			{{ countLabel }}
		</p>
		<NcNoteCard v-if="status.lastError" type="warning" data-testid="donl-harvest-error">
			{{ t('opencatalogi', 'The last check of data.overheid.nl failed: {error}', { error: status.lastError }) }}
		</NcNoteCard>

		<NcTextField
			v-model="form.apiUrl"
			:label="t('opencatalogi', 'data.overheid.nl API address')"
			:helperText="t('opencatalogi', 'The CKAN API root, ending in /api/3.')"
			data-testid="donl-api-url" />
		<NcTextField
			v-model="form.source"
			:label="t('opencatalogi', 'Source catalogue on data.overheid.nl')"
			:helperText="t('opencatalogi', 'The source_catalog value data.overheid.nl gives the datasets of this feed.')"
			data-testid="donl-source" />
		<NcTextField
			v-model="form.harvesterPattern"
			:label="t('opencatalogi', 'User agent of the data.overheid.nl harvester')"
			:helperText="t('opencatalogi', 'A regular expression. A feed request whose user agent matches it counts as a harvest.')"
			data-testid="donl-harvester-pattern" />

		<div class="donl-harvest-status__actions">
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="donl-save"
				@click="save(status.status)">
				{{ t('opencatalogi', 'Save') }}
			</NcButton>
			<NcButton
				v-if="status.status === 'not_requested'"
				variant="secondary"
				:disabled="busy"
				data-testid="donl-mark-requested"
				@click="save('requested')">
				{{ t('opencatalogi', 'Mark as requested') }}
			</NcButton>
			<NcButton
				v-if="status.status !== 'registered'"
				variant="secondary"
				:disabled="busy"
				data-testid="donl-mark-registered"
				@click="save('registered')">
				{{ t('opencatalogi', 'Mark as registered') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'

const URL = '/apps/opencatalogi/api/dcat/donl-registration'

/**
 * Whether data.overheid.nl harvests the DCAT feed (REQ-DCC-002).
 *
 * The operator records requested and registered and the three settings the
 * evidence needs. "Harvested" is shown only on evidence: a recorded harvester
 * fetch or a dataset count above zero from the daily check.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
 */
export default {
	name: 'DonlHarvestStatus',
	components: { NcButton, NcNoteCard, NcTextField },

	data() {
		return {
			status: {
				status: 'not_requested',
				requestedAt: '',
				registeredAt: '',
				lastFetchAt: '',
				lastCheckAt: '',
				donlDatasetCount: null,
				donlCountAt: '',
				feedDatasetCount: null,
				lastError: '',
				harvested: false,
			},

			form: { apiUrl: '', source: '', harvesterPattern: '' },
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002 */
		statusLabel() {
			if (this.status.status === 'registered') {
				return this.t('opencatalogi', 'Registered on {date}.', { date: this.day(this.status.registeredAt) })
			}
			if (this.status.status === 'requested') {
				return this.t('opencatalogi', 'Requested on {date}.', { date: this.day(this.status.requestedAt) })
			}
			return this.t('opencatalogi', 'Registration not requested yet.')
		},

		/** @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002 */
		harvestLabel() {
			if (this.status.harvested !== true) {
				return this.t('opencatalogi', 'Not harvested yet: no harvester visit and no datasets on data.overheid.nl.')
			}
			if (this.status.lastFetchAt) {
				return this.t('opencatalogi', 'Harvested. Last harvested on {date}.', { date: this.day(this.status.lastFetchAt) })
			}
			return this.t('opencatalogi', 'Harvested.')
		},

		/** @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002 */
		countLabel() {
			if (this.status.donlDatasetCount === null) {
				return ''
			}
			return this.t('opencatalogi', '{donl} of {feed} datasets on data.overheid.nl, checked on {date}.', {
				donl: this.status.donlDatasetCount,
				feed: this.status.feedDatasetCount === null ? '?' : this.status.feedDatasetCount,
				date: this.day(this.status.donlCountAt),
			})
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The date part of a stored moment.
		 *
		 * @param {string} moment An ISO moment.
		 * @return {string} The date, or the moment as it is.
		 */
		day(moment) {
			return (moment || '').slice(0, 10)
		},

		/**
		 * Take a status the server answered.
		 *
		 * @param {object} data The status.
		 * @return {void}
		 */
		take(data) {
			this.status = { ...this.status, ...data }
			this.form = {
				apiUrl: data.apiUrl || '',
				source: data.source || '',
				harvesterPattern: data.harvesterPattern || '',
			}
		},

		/**
		 * Read the registration.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			try {
				const { data } = await axios.get(generateUrl(URL))
				this.take(data)
			} catch {
				this.error = this.t('opencatalogi', 'The data.overheid.nl registration could not be loaded.')
			}
		},

		/**
		 * Record a status with the settings in the form.
		 *
		 * @param {string} status The status to record.
		 * @return {Promise<void>}
		 */
		async save(status) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl(URL), { ...this.form, status })
				this.take(data)
			} catch (error) {
				const message = error && error.response && error.response.data ? error.response.data.error : ''
				this.error = message || this.t('opencatalogi', 'That did not work. Try again.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.donl-harvest-status {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.donl-harvest-status__heading {
	margin: 12px 0 0;
}

.donl-harvest-status__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.donl-harvest-status__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
