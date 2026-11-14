<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<fieldset class="catalog-retention-defaults" data-testid="catalog-retention-defaults">
		<legend class="catalog-retention-defaults__legend">
			{{ t('opencatalogi', 'Retention defaults') }}
		</legend>
		<p class="catalog-retention-defaults__hint">
			{{
				t(
					'opencatalogi',
					'A publication of this catalogue gets these when it is first published and its own retention fields are empty. Changing them later does not change existing publications.',
				)
			}}
		</p>
		<NcLoadingIcon v-if="loading" :size="20" />
		<NcNoteCard v-else-if="loadError" type="error">
			<p>{{ loadError }}</p>
		</NcNoteCard>
		<template v-else>
			<table class="catalog-retention-defaults__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('opencatalogi', 'Information category') }}
						</th>
						<th scope="col">
							{{ t('opencatalogi', 'Term in months') }}
						</th>
						<th scope="col">
							{{ t('opencatalogi', 'When the term ends') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.code" :data-testid="'retention-default-' + row.code">
						<th scope="row">
							{{ rowLabel(row) }}
						</th>
						<td>
							<NcTextField
								v-model="row.termMonths"
								type="number"
								min="1"
								step="1"
								:label="t('opencatalogi', 'Term in months for {category}', { category: rowLabel(row) })"
								:labelOutside="false"
								:disabled="disabled || saving"
								:error="!termIsValid(row)"
								:helperText="termIsValid(row) ? '' : t('opencatalogi', 'A whole number of months, 1 or more')" />
						</td>
						<td>
							<NcSelect
								:modelValue="actionOption(row.action)"
								:options="actionOptions"
								:inputLabel="t('opencatalogi', 'When the term of {category} ends', { category: rowLabel(row) })"
								:disabled="disabled || saving"
								@update:modelValue="row.action = $event ? $event.id : ''" />
						</td>
					</tr>
				</tbody>
			</table>
			<NcNoteCard v-if="saveError" type="error">
				<p>{{ saveError }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="saved" type="success">
				<p>{{ t('opencatalogi', 'Retention defaults saved') }}</p>
			</NcNoteCard>
			<NcButton
				:disabled="disabled || saving || !valid"
				data-testid="catalog-retention-defaults-save"
				@click="save">
				{{ t('opencatalogi', 'Save retention defaults') }}
			</NcButton>
		</template>
	</fieldset>
</template>

<script>
import axios from '@nextcloud/axios'
import { getLanguage, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { catalogRows, FALLBACK, RETENTION_ACTIONS, termIsValid, withCatalogRows } from '../services/retentionDefaults.js'

/**
 * CatalogRetentionDefaultsFields: the retention default per Woo information
 * category, and a catalogue-wide fallback, for one catalogue. Reads and saves
 * `GET`/`POST /api/retention/defaults`; the server applies them when a
 * publication is first published (RET-004).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-31
 */
export default {
	name: 'CatalogRetentionDefaultsFields',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** The catalogue's slug, which keys its defaults. */
		catalogSlug: { type: String, required: true },
		/** Whether the fields are read-only. */
		disabled: { type: Boolean, default: false },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			saving: false,
			saveError: '',
			saved: false,
			defaults: {},
			rows: [],
		}
	},

	computed: {
		actionOptions() {
			const labels = {
				review: t('opencatalogi', 'Flag for review'),
				depublish: t('opencatalogi', 'Depublish'),
				archive: t('opencatalogi', 'Archive'),
			}
			return RETENTION_ACTIONS.map((id) => ({ id, label: labels[id] }))
		},

		valid() {
			return this.rows.every(termIsValid)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,
		termIsValid,

		/**
		 * Load the categories and the stored defaults.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const [categories, defaults] = await Promise.all([
					axios.get(generateUrl('/apps/opencatalogi/api/woo/categories')),
					axios.get(generateUrl('/apps/opencatalogi/api/retention/defaults')),
				])
				const dutch = getLanguage().startsWith('nl')
				const list = (categories.data?.results ?? []).map((category) => ({
					code: String(category.code),
					label: (dutch ? category.nl : category.en) || category.nl || String(category.code),
				}))
				this.defaults = defaults.data?.defaults ?? {}
				this.rows = catalogRows(this.defaults, this.catalogSlug, list)
			} catch {
				this.loadError = t('opencatalogi', 'The retention defaults could not be loaded')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save this catalogue's rows into the defaults map.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.saveError = ''
			this.saved = false
			try {
				const next = withCatalogRows(this.defaults, this.catalogSlug, this.rows)
				const response = await axios.post(generateUrl('/apps/opencatalogi/api/retention/defaults'), { defaults: next })
				this.defaults = response.data?.defaults ?? next
				this.saved = true
			} catch (error) {
				this.saveError = error?.response?.data?.error || t('opencatalogi', 'The retention defaults could not be saved')
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {{code: string, label: string}} row - the row.
		 * @return {string} the row's name.
		 */
		rowLabel(row) {
			return row.code === FALLBACK ? t('opencatalogi', 'Any other category') : row.label
		},

		/**
		 * @param {string} action - the stored action.
		 * @return {?object} the selected option.
		 */
		actionOption(action) {
			return this.actionOptions.find((option) => option.id === action) ?? null
		},
	},
}
</script>

<style scoped>
.catalog-retention-defaults {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	margin-block: 8px;
}

.catalog-retention-defaults__legend {
	font-weight: bold;
	padding-inline: 4px;
}

.catalog-retention-defaults__hint {
	color: var(--color-text-maxcontrast);
}

.catalog-retention-defaults__table {
	width: 100%;
	border-collapse: collapse;
}

.catalog-retention-defaults__table th,
.catalog-retention-defaults__table td {
	padding: 4px;
	text-align: start;
	vertical-align: top;
}
</style>
