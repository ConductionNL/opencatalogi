<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - WOO redaction review surface (woo-transparency).
  -
  - The genuinely WOO-specific frontend: for a document assessed "Deels openbaar"
  - it lists the entities Docudesk detected, lets the officer select which to
  - redact (not all-or-nothing), attach a weigeringsgrond per redaction, add a
  - manual region, and request a preview. The redaction instructions are sent to
  - Docudesk for execution (ADR-022 — document processing is the leaf's concern).
  - The queue/board itself is NOT here: that is the OpenRegister deck widget on
  - the batch detail page.
  -->
<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { buildRedactionInstructions, pagesWithEntities } from '../../services/wooHelpers.js'

export default {
	name: 'WooRedactionView',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
	},
	props: {
		/** The document-assessment object id. */
		documentId: {
			type: String,
			default: '',
		},
		/** The owning batch id (path scoping for the assessment update). */
		batchId: {
			type: String,
			default: '',
		},
		/** Entities detected by Docudesk: [{ id, text, type, page }]. */
		entities: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			t,
			loading: false,
			error: null,
			// Per-entity redaction selection keyed by entity id.
			selected: {},
			// Per-entity weigeringsgrond (article reference) keyed by entity id.
			grounds: {},
			grondOptions: [],
			previewUrl: null,
		}
	},
	computed: {
		/** @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-redaction-with-woo-context */
		pagesWithEntities() {
			return pagesWithEntities(this.entities)
		},
		/** @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-redaction-with-woo-context */
		selectedCount() {
			return Object.values(this.selected).filter(Boolean).length
		},
	},
	mounted() {
		this.loadGronden()
	},
	methods: {
		/**
		 * Load the WOO weigeringsgronden catalogue for the per-redaction selector.
		 *
		 * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-weigeringsgronden-refusal-grounds
		 * @return {Promise<void>}
		 */
		async loadGronden() {
			try {
				const { data } = await axios.get(generateUrl('/apps/opencatalogi/api/woo/weigeringsgronden'))
				this.grondOptions = (data.results || []).map((g) => ({
					id: g.article,
					label: `${g.article} ${g.description}`,
				}))
			} catch (err) {
				this.error = err.message
			}
		},
		/**
		 * Toggle an entity's redaction selection.
		 *
		 * @param {string} entityId The entity id.
		 * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-redaction-with-woo-context
		 * @return {void}
		 */
		toggleEntity(entityId) {
			this.$set(this.selected, entityId, !this.selected[entityId])
		},
		/**
		 * Build the redaction-instruction payload (entity -> ground mapping).
		 *
		 * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-redaction-with-woo-context
		 * @return {Array<object>} The redaction instructions.
		 */
		buildInstructions() {
			return buildRedactionInstructions(this.entities, this.selected, this.grounds)
		},
		/**
		 * Request a redaction preview (delegated to Docudesk; URL surfaced here).
		 *
		 * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-redaction-with-woo-context
		 * @return {Promise<void>}
		 */
		async requestPreview() {
			this.loading = true
			this.error = null
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/opencatalogi/api/woo/batches/${this.batchId}/documents/${this.documentId}`),
					{
						assessment: 'deels_openbaar',
						weigeringsgronden: this.buildInstructions().map((i) => i.weigeringsgrond).filter(Boolean),
						redactionInstructions: this.buildInstructions(),
						preview: true,
					},
				)
				this.previewUrl = data.previewUrl || null
				this.$emit('preview', data)
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<template>
	<div class="woo-redaction">
		<div v-if="error" class="woo-redaction__error">
			{{ error }}
		</div>

		<p class="woo-redaction__summary">
			{{ t('opencatalogi', '{selected} of {total} detected entities marked for redaction', { selected: selectedCount, total: entities.length }) }}
		</p>

		<ul class="woo-redaction__pages">
			<li v-for="page in pagesWithEntities" :key="page" class="woo-redaction__page-chip">
				{{ t('opencatalogi', 'Page {page}', { page }) }}
			</li>
		</ul>

		<table class="woo-redaction__table">
			<thead>
				<tr>
					<th>{{ t('opencatalogi', 'Redact') }}</th>
					<th>{{ t('opencatalogi', 'Entity') }}</th>
					<th>{{ t('opencatalogi', 'Type') }}</th>
					<th>{{ t('opencatalogi', 'Page') }}</th>
					<th>{{ t('opencatalogi', 'Refusal ground') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="entity in entities" :key="entity.id">
					<td>
						<NcCheckboxRadioSwitch
							:checked="!!selected[entity.id]"
							@update:checked="toggleEntity(entity.id)" />
					</td>
					<td>{{ entity.text }}</td>
					<td>{{ entity.type }}</td>
					<td>{{ entity.page }}</td>
					<td>
						<NcSelect
							v-model="grounds[entity.id]"
							:options="grondOptions"
							:disabled="!selected[entity.id]"
							:input-label="t('opencatalogi', 'Refusal ground')"
							:placeholder="t('opencatalogi', 'Select a refusal ground')" />
					</td>
				</tr>
			</tbody>
		</table>

		<div class="woo-redaction__actions">
			<NcButton type="primary" :disabled="loading || selectedCount === 0" @click="requestPreview">
				<template v-if="loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Voorbeeld') }}
			</NcButton>
		</div>

		<div v-if="previewUrl" class="woo-redaction__preview">
			<a :href="previewUrl" target="_blank" rel="noopener noreferrer">
				{{ t('opencatalogi', 'Open redaction preview') }}
			</a>
		</div>
	</div>
</template>

<style scoped>
.woo-redaction {
	padding: 12px 16px;
}

.woo-redaction__error {
	color: var(--color-error);
	margin-bottom: 8px;
}

.woo-redaction__summary {
	font-weight: bold;
	margin-bottom: 8px;
}

.woo-redaction__pages {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	list-style: none;
	padding: 0;
	margin: 0 0 12px;
}

.woo-redaction__page-chip {
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius-pill);
	padding: 2px 10px;
	font-size: 0.85em;
}

.woo-redaction__table {
	width: 100%;
	border-collapse: collapse;
}

.woo-redaction__table th,
.woo-redaction__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.woo-redaction__actions {
	margin-top: 12px;
}

.woo-redaction__preview {
	margin-top: 12px;
}
</style>
