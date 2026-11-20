<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="council-harvest" data-testid="council-harvest">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading && !status" :size="32" />

		<template v-else-if="status">
			<NcNoteCard
				v-if="!status.integriq"
				type="info"
				data-testid="council-harvest-needs-integriq">
				{{ t('opencatalogi', 'The council documents harvest needs integriq. Install and enable integriq, then come back here.') }}
			</NcNoteCard>

			<template v-else>
				<NcNoteCard
					v-if="status.decidiq"
					type="warning"
					data-testid="council-harvest-decidiq">
					{{ t('opencatalogi', 'decidiq already publishes council papers here. Running both creates duplicates.') }}
				</NcNoteCard>

				<NcSelect
					v-model="chosen"
					:options="status.sources"
					label="label"
					:inputLabel="t('opencatalogi', 'Source')"
					:placeholder="t('opencatalogi', 'Choose a Notubiz or iBabs source')"
					:disabled="busy"
					data-testid="council-harvest-source" />

				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="status.publishOnArrival"
					:disabled="busy || !status.source"
					data-testid="council-harvest-publish"
					@update:modelValue="publish">
					{{ t('opencatalogi', 'Publish on arrival') }}
				</NcCheckboxRadioSwitch>
				<p class="council-harvest__hint">
					{{ t('opencatalogi', 'Off: each document arrives as a concept, so someone checks it before it is public.') }}
				</p>

				<ul class="council-harvest__checks">
					<li data-testid="council-harvest-flow">
						<InformationOutline :size="20" />
						<span>{{ flowLabel }}</span>
					</li>
					<li data-testid="council-harvest-last-run">
						<InformationOutline :size="20" />
						<span>{{ lastRunLabel }}</span>
					</li>
				</ul>

				<div class="council-harvest__actions">
					<NcButton
						variant="secondary"
						:disabled="busy || !chosen"
						data-testid="council-harvest-setup"
						@click="act('setup', { source: chosen.slug })">
						{{ t('opencatalogi', 'Set up') }}
					</NcButton>
					<NcButton
						v-if="!status.flow.enabled"
						variant="primary"
						:disabled="busy || !status.source || !status.flow.imported"
						data-testid="council-harvest-enable"
						@click="act('enable')">
						{{ t('opencatalogi', 'Switch on') }}
					</NcButton>
					<NcButton
						v-else
						variant="secondary"
						:disabled="busy"
						data-testid="council-harvest-disable"
						@click="act('disable')">
						{{ t('opencatalogi', 'Switch off') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="busy || !status.flow.enabled"
						data-testid="council-harvest-run"
						@click="act('run')">
						{{ t('opencatalogi', 'Run now') }}
					</NcButton>
					<a
						v-if="setUpSource"
						:href="sourceUrl"
						data-testid="council-harvest-source-link">
						{{ t('opencatalogi', 'Open the source in integriq') }}
					</a>
				</div>
			</template>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import { lastRunLabel as harvestLastRunLabel } from '../../services/harvestRunLabel.js'

const BASE = '/apps/opencatalogi/api/settings/council-harvest'

/**
 * The Council documents section of the admin settings: which council system
 * the harvest reads, whether documents are public on arrival, and the
 * set-up, switch and run actions.
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */
export default {
	name: 'CouncilHarvest',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		InformationOutline,
	},

	data() {
		return {
			status: null,
			chosen: null,
			loading: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001 */
		setUpSource() {
			return (this.status?.sources ?? []).find((source) => source.slug === this.status.source) ?? null
		},

		/** @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001 */
		sourceUrl() {
			return generateUrl('/apps/integriq/sources/{uuid}', { uuid: this.setUpSource.uuid })
		},

		/** @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001 */
		flowLabel() {
			const flow = this.status.flow
			if (!this.status.source) {
				return this.t('opencatalogi', 'Not set up yet. Choose a source and press Set up.')
			}
			if (!flow.imported) {
				return this.t('opencatalogi', 'The harvest flow is not imported yet. Reimport the configuration at the top of this page.')
			}
			if (!flow.enabled) {
				return this.t('opencatalogi', 'The harvest is off.')
			}
			return this.t('opencatalogi', 'The harvest is on. It runs on schedule {cron}.', { cron: flow.cron ?? '' })
		},

		/** @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001 */
		lastRunLabel() {
			return harvestLastRunLabel(this.status.lastRun)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the status, and preselect the set-up source.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(BASE))
				this.status = data
				this.chosen = this.chosen ?? this.setUpSource
				this.error = ''
			} catch {
				this.error = this.t('opencatalogi', 'The council documents harvest status could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Turn Publish on arrival on or off.
		 *
		 * @param {boolean} value Whether documents are public on arrival.
		 * @return {Promise<void>}
		 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-each-public-council-document-becomes-a-publication-with-its-file-req-cdh-002
		 */
		publish(value) {
			return this.act('publish-on-arrival', { publish: value })
		},

		/**
		 * Post one action, reload the status, and show the refusal if there was one.
		 *
		 * @param {string} action The route after the base.
		 * @param {object} [payload] The body.
		 * @return {Promise<void>}
		 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
		 */
		async act(action, payload = {}) {
			this.busy = true
			let refusal = ''
			try {
				await axios.post(generateUrl(BASE + '/' + action), payload)
			} catch (e) {
				refusal = e?.response?.data?.error ?? this.t('opencatalogi', 'That did not work. Try again.')
			} finally {
				this.busy = false
			}
			await this.load()
			// After the reload, so the status read does not clear the refusal.
			this.error = refusal || this.error
		},
	},
}
</script>

<style scoped>
.council-harvest__hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
}

.council-harvest__checks {
	list-style: none;
	padding: 0;
	margin: 0 0 12px;
}

.council-harvest__checks li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.council-harvest__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}
</style>
