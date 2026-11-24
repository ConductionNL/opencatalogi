<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="licence-picker" data-testid="licence-picker">
		<NcSelect
			:modelValue="selected"
			:options="options"
			:inputLabel="inputLabel || t('opencatalogi', 'Licence')"
			:placeholder="placeholder"
			:disabled="disabled || loading"
			:loading="loading"
			:clearable="clearable"
			@update:modelValue="choose" />
		<p
			v-if="unlisted"
			class="licence-picker__note"
			data-testid="licence-picker-unlisted">
			{{ t('opencatalogi', 'The stored licence "{licence}" is not on the EU licences list. The feed leaves it out until you choose one from the list.', { licence: modelValue }) }}
		</p>
		<p
			v-if="error"
			class="licence-picker__note"
			role="alert"
			data-testid="licence-picker-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { getLanguage, translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { fetchLicences, licenceOptionFor, licenceOptions } from '../services/licences.js'

/**
 * LicencePicker: chooses a licence from the EU licences authority list and
 * stores its authority IRI (REQ-DCC-001). Nothing can be typed outside the
 * list; the server refuses an unlisted value on save all the same. A stored
 * value that is not on the list is named under the field, so a legacy
 * free-text licence is visible rather than silently shown as empty.
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
export default {
	name: 'LicencePicker',
	components: { NcSelect },

	props: {
		/** The stored licence: an authority IRI, or empty. */
		modelValue: { type: String, default: '' },
		/** The field label. */
		inputLabel: { type: String, default: '' },
		/** The text shown while nothing is chosen. */
		placeholder: { type: String, default: '' },
		/** Whether the choice can be cleared back to empty. */
		clearable: { type: Boolean, default: true },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			rows: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4 */
		options() {
			return licenceOptions(this.rows, getLanguage())
		},

		/** @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4 */
		selected() {
			return licenceOptionFor(this.options, this.modelValue)
		},

		/**
		 * Whether a stored value is not on the list (only known once the list is read).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
		 */
		unlisted() {
			return !this.loading && this.error === '' && String(this.modelValue || '').trim() !== '' && this.selected === null
		},
	},

	/**
	 * Read the licence list once the picker is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 */
	async mounted() {
		try {
			this.rows = await fetchLicences()
		} catch {
			this.error = t('opencatalogi', 'The licence list could not be loaded.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * Emit the chosen licence's IRI, or an empty value when cleared.
		 *
		 * @param {{id: string}|null} option The chosen option.
		 * @return {void}
		 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
		 */
		choose(option) {
			this.$emit('update:modelValue', option?.id || '')
		},
	},
}
</script>

<style scoped>
.licence-picker__note {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}
</style>
