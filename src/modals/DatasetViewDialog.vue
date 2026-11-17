<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal
		v-if="open"
		:name="view ? t('opencatalogi', 'Edit view') : t('opencatalogi', 'Show on the public page')"
		labelId="dataset-view-dialog"
		data-testid="dataset-view-dialog"
		@close="$emit('close')">
		<form class="dataset-view" @submit.prevent="save">
			<h2 id="dataset-view-dialog" class="dataset-view__heading">
				{{ view ? t('opencatalogi', 'Edit view') : t('opencatalogi', 'Show on the public page') }}
			</h2>
			<p class="dataset-view__table">
				{{ t('opencatalogi', 'Table: {title}', { title: table.title || table.id }) }}
			</p>

			<NcSelect
				v-model="kindOption"
				:options="kindOptions"
				:clearable="false"
				:inputLabel="t('opencatalogi', 'Show as')"
				data-testid="dataset-view-kind" />
			<NcTextField
				:modelValue="form.title"
				:label="t('opencatalogi', 'Title readers see')"
				data-testid="dataset-view-title"
				@update:modelValue="form.title = $event" />

			<NcSelect
				v-if="form.kind === 'table'"
				v-model="form.columns"
				:options="columns"
				multiple
				:keepOpen="true"
				:inputLabel="t('opencatalogi', 'Columns, in order')"
				data-testid="dataset-view-columns" />

			<template v-if="isChart">
				<NcSelect
					v-model="form.x"
					:options="columns"
					:inputLabel="t('opencatalogi', 'Group by (horizontal axis)')"
					data-testid="dataset-view-x" />
				<NcSelect
					v-model="aggregateOption"
					:options="aggregateOptions"
					:clearable="false"
					:inputLabel="t('opencatalogi', 'Value per group')"
					data-testid="dataset-view-aggregate" />
				<NcSelect
					v-if="form.aggregate !== 'count'"
					v-model="form.y"
					:options="numeric"
					:inputLabel="t('opencatalogi', 'Numeric column to combine')"
					data-testid="dataset-view-y" />
			</template>

			<template v-if="form.kind === 'map'">
				<NcSelect
					v-model="form.latitude"
					:options="numeric"
					:inputLabel="t('opencatalogi', 'Latitude column (WGS84)')"
					data-testid="dataset-view-latitude" />
				<NcSelect
					v-model="form.longitude"
					:options="numeric"
					:inputLabel="t('opencatalogi', 'Longitude column (WGS84)')"
					data-testid="dataset-view-longitude" />
				<NcSelect
					v-model="form.label"
					:options="columns"
					:inputLabel="t('opencatalogi', 'Marker label (optional)')"
					data-testid="dataset-view-label" />
			</template>

			<NcNoteCard
				v-if="error"
				type="error"
				data-testid="dataset-view-error">
				{{ error }}
			</NcNoteCard>

			<section
				v-if="preview"
				class="dataset-view__preview"
				aria-live="polite"
				data-testid="dataset-view-preview">
				<h3 class="dataset-view__preview-heading">
					{{ t('opencatalogi', 'Preview of what readers get') }}
				</h3>
				<p>{{ previewSentence }}</p>
				<NcNoteCard
					v-if="preview.truncated"
					type="warning"
					data-testid="dataset-view-truncated">
					{{ truncatedSentence }}
				</NcNoteCard>
				<p v-if="preview.skipped">
					{{ t('opencatalogi', 'Rows left off the map without usable coordinates: {count}', { count: preview.skipped }) }}
				</p>
			</section>

			<div class="dataset-view__actions">
				<NcButton
					:disabled="busy"
					@click="$emit('close')">
					{{ preview ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					:disabled="busy"
					data-testid="dataset-view-save">
					{{ t('opencatalogi', 'Save view') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { AGGREGATES, columnNames, missingField, numericColumns, previewFigures, VIEW_KINDS, viewForm, viewPayload } from '../services/datasetViews.js'

/**
 * Add or change one view of a published table (REQ-DSV-001): the kind, the
 * title and the column pickers of that kind, saved through the editor route;
 * after a save the dialog previews the view's public data answer, so the
 * editor sees how many rows, groups or points readers get.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
export default {
	name: 'DatasetViewDialog',
	components: { NcButton, NcModal, NcNoteCard, NcSelect, NcTextField },

	props: {
		open: { type: Boolean, default: false },
		publicationId: { type: String, required: true },
		catalogSlug: { type: String, default: '' },
		table: { type: Object, required: true },
		/** The saved view to change, or null to add one. */
		view: { type: Object, default: null },
		/** Where a new view goes on the page. */
		position: { type: Number, default: 0 },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: viewForm(this.view),
			savedId: this.view ? String(this.view.id || '') : '',
			busy: false,
			error: '',
			preview: null,
		}
	},

	computed: {
		/** @spec exclude presentational: the table's column names. */
		columns() {
			return columnNames(this.table.dictionary)
		},

		/** @spec exclude presentational: the numeric column names. */
		numeric() {
			return numericColumns(this.table.dictionary)
		},

		/** @spec exclude presentational: whether the form draws a chart. */
		isChart() {
			return this.form.kind === 'bar' || this.form.kind === 'line'
		},

		/** @spec exclude presentational: the kinds as options. */
		kindOptions() {
			const labels = {
				table: this.t('opencatalogi', 'Table'),
				bar: this.t('opencatalogi', 'Bar chart'),
				line: this.t('opencatalogi', 'Line chart'),
				map: this.t('opencatalogi', 'Map'),
			}
			return VIEW_KINDS.map((id) => ({ id, label: labels[id] }))
		},

		kindOption: {
			/** @spec exclude presentational: the chosen kind as an option. */
			get() {
				return this.kindOptions.find((option) => option.id === this.form.kind) || null
			},

			/**
			 * @param {{id: string}|null} option The chosen option.
			 * @spec exclude presentational: writes the chosen kind.
			 */
			set(option) {
				this.form.kind = option ? option.id : 'table'
			},
		},

		/** @spec exclude presentational: the aggregates as options. */
		aggregateOptions() {
			const labels = {
				count: this.t('opencatalogi', 'Number of rows'),
				sum: this.t('opencatalogi', 'Sum'),
				avg: this.t('opencatalogi', 'Average'),
			}
			return AGGREGATES.map((id) => ({ id, label: labels[id] }))
		},

		aggregateOption: {
			/** @spec exclude presentational: the chosen aggregate as an option. */
			get() {
				return this.aggregateOptions.find((option) => option.id === this.form.aggregate) || null
			},

			/**
			 * @param {{id: string}|null} option The chosen option.
			 * @spec exclude presentational: writes the chosen aggregate.
			 */
			set(option) {
				this.form.aggregate = option ? option.id : 'count'
			},
		},

		/** @spec exclude presentational: the preview in words. */
		previewSentence() {
			const count = this.preview.count
			if (this.form.kind === 'map') {
				return this.t('opencatalogi', 'Points on the map: {count}', { count })
			}
			if (this.isChart) {
				return this.t('opencatalogi', 'Groups in the chart: {count}', { count })
			}
			return this.t('opencatalogi', 'Rows on the first page: {count} of {total}', { count, total: this.preview.total })
		},

		/** @spec exclude presentational: the truncation warning in words. */
		truncatedSentence() {
			if (this.form.kind === 'map') {
				return this.t('opencatalogi', 'The table has more points than a map shows; readers see the first 5,000.')
			}
			return this.t('opencatalogi', 'The column has more values than a chart shows; readers see the first 200 groups.')
		},
	},

	watch: {
		/**
		 * Start every opening from the view it edits.
		 *
		 * @param {boolean} shown Whether the dialog just opened.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		open(shown) {
			if (shown) {
				this.form = viewForm(this.view)
				this.savedId = this.view ? String(this.view.id || '') : ''
				this.error = ''
				this.preview = null
			}
		},
	},

	methods: {
		/**
		 * The sentence for a form that is not complete yet.
		 *
		 * @param {string} missing The missing field's code.
		 * @return {string} The sentence.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		missingSentence(missing) {
			const sentences = {
				title: this.t('opencatalogi', 'Give the view a title.'),
				columns: this.t('opencatalogi', 'Choose at least one column.'),
				x: this.t('opencatalogi', 'Choose the column to group by.'),
				y: this.t('opencatalogi', 'Choose the numeric column to combine.'),
				coordinates: this.t('opencatalogi', 'Choose the latitude and longitude columns.'),
			}
			return sentences[missing]
		},

		/**
		 * Save the view, then preview its public data.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		async save() {
			const missing = missingField(this.form)
			if (missing) {
				this.error = this.missingSentence(missing)
				return
			}
			this.busy = true
			this.error = ''
			this.preview = null
			try {
				const position = this.savedId ? (this.view?.position ?? null) : this.position
				const { data } = await axios.post(
					generateUrl('/apps/opencatalogi/api/publications/{id}/tables/{tableId}/views', { id: this.publicationId, tableId: this.table.id }),
					viewPayload(this.form, this.savedId, position),
				)
				this.savedId = String(data.id || this.savedId)
				this.$emit('saved', data)
				await this.loadPreview()
			} catch (error) {
				this.error = this.refusal(error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Read the saved view's public data answer; no preview without a catalogue
		 * or while the publication is not public.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
		 */
		async loadPreview() {
			if (!this.catalogSlug || !this.savedId) {
				return
			}
			try {
				const { data } = await axios.get(generateUrl(
					'/apps/opencatalogi/api/{catalogSlug}/{publicationId}/tables/{tableId}/views/{viewId}/data',
					{ catalogSlug: this.catalogSlug, publicationId: this.publicationId, tableId: this.table.id, viewId: this.savedId },
				))
				this.preview = previewFigures(this.form.kind, data)
			} catch {
				this.preview = null
			}
		},

		/**
		 * Put a refusal into words.
		 *
		 * @param {object} error The axios error.
		 * @return {string} The sentence.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		refusal(error) {
			const response = error && error.response ? error.response : {}
			if (response.status === 403) {
				return this.t('opencatalogi', 'You may not change this publication.')
			}
			if (response.status === 400 && response.data && response.data.message) {
				return response.data.message
			}
			return this.t('opencatalogi', 'That did not work. Try again.')
		},
	},
}
</script>

<style scoped>
.dataset-view {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.dataset-view__heading,
.dataset-view__table,
.dataset-view__preview-heading {
	margin: 0;
}

.dataset-view__table {
	color: var(--color-text-maxcontrast);
}

.dataset-view__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.dataset-view__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
