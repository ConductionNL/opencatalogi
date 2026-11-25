<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="organisation-type" data-testid="organisation-type">
		<h3 class="organisation-type__heading">
			{{ t('opencatalogi', 'Organisation type') }}
		</h3>
		<p class="organisation-type__hint">
			{{
				t(
					'opencatalogi',
					'Choose the kind of organisation you publish for. The information categories the Woo does not apply to for it are proposed for hiding.',
				)
			}}
		</p>
		<NcSelect
			:modelValue="selectedOption"
			:options="typeOptions"
			:inputLabel="t('opencatalogi', 'Organisation type')"
			:clearable="false"
			:disabled="busy"
			data-testid="organisation-type-select"
			@update:modelValue="choose($event)" />
		<NcNoteCard
			v-for="message in errors"
			:key="message"
			type="error"
			data-testid="organisation-type-error">
			{{ message }}
		</NcNoteCard>
		<NcNoteCard
			v-if="done"
			type="success"
			data-testid="organisation-type-done">
			{{ t('opencatalogi', 'The checked categories are hidden.') }}
		</NcNoteCard>
		<div v-if="proposal.length > 0" data-testid="organisation-type-proposal">
			<h4>{{ t('opencatalogi', 'Proposed for hiding') }}</h4>
			<ul class="organisation-type__list">
				<li
					v-for="item in proposal"
					:key="item.tooiUri"
					:data-testid="'organisation-type-proposal-' + item.category">
					<NcCheckboxRadioSwitch
						:modelValue="checked.includes(item.tooiUri)"
						:disabled="busy"
						@update:modelValue="toggle(item.tooiUri, $event)">
						{{ labelOf(item) }}
					</NcCheckboxRadioSwitch>
					<p class="organisation-type__basis">
						{{ t('opencatalogi', 'Legal basis') }}: {{ item.article }}:
						<q lang="nl">{{ item.basis }}</q>
					</p>
				</li>
			</ul>
			<NcButton
				variant="primary"
				:disabled="busy || checked.length === 0"
				data-testid="organisation-type-hide"
				@click="hideChecked">
				{{ t('opencatalogi', 'Hide the checked categories') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { CHOICES_URL } from './valueListOrder.js'

/**
 * The URL of the organisation type.
 */
export const TYPE_URL = '/apps/opencatalogi/api/woo/organisation-type'

/**
 * The list the information categories are.
 */
export const SCHEME = 'informatiecategorieen'

/**
 * The label of each organisation type the server offers.
 *
 * @return {object} Labels by type.
 */
export function typeLabels() {
	return {
		gemeente: t('opencatalogi', 'Municipality'),
		provincie: t('opencatalogi', 'Province'),
		waterschap: t('opencatalogi', 'Water board'),
		'gemeenschappelijke-regeling': t('opencatalogi', 'Joint arrangement'),
		rijksoverheid: t('opencatalogi', 'Central government'),
		'zelfstandig-bestuursorgaan': t('opencatalogi', 'Independent administrative body'),
		overig: t('opencatalogi', 'Other'),
	}
}

/**
 * The administrator sets the organisation type and confirms or changes the proposal per category (REQ-WVL-004).
 *
 * Choosing a type hides nothing. The proposal comes from the shipped
 * applicability table, each entry with the Woo article and the scope it
 * rests on; every proposed category starts checked, the administrator
 * unchecks what their organisation does publish, and the checked ones are
 * written as `hidden` on their value-list choice. The server refuses to hide
 * a category with publications filed under it and says how many.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 */
export default {
	name: 'OrganisationTypeProposal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
	},

	emits: ['hidden'],

	data() {
		return {
			type: null,
			types: [],
			proposal: [],
			checked: [],
			labels: {},
			errors: [],
			done: false,
			busy: false,
		}
	},

	computed: {
		/**
		 * The organisation types as select options.
		 *
		 * @return {Array<object>} The options.
		 */
		typeOptions() {
			const labels = typeLabels()
			return this.types.map((type) => ({ id: type, label: labels[type] || type }))
		},

		/**
		 * The option of the type set, or null.
		 *
		 * @return {object|null} The option.
		 */
		selectedOption() {
			return this.typeOptions.find((option) => option.id === this.type) || null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the type, its proposal and the category labels.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
		 * @return {Promise<void>}
		 */
		async load() {
			this.errors = []
			try {
				const { data } = await axios.get(generateUrl(TYPE_URL))
				this.apply(data)
				const choices = await axios.get(generateUrl(CHOICES_URL), {
					params: { scheme: SCHEME },
				})
				this.labels = labelsByUri(choices.data?.entries || [])
			} catch (err) {
				this.errors = [err.response?.data?.error || err.message]
			}
		},

		/**
		 * Set the organisation type and show its proposal; nothing is hidden yet.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
		 * @param {object|null} option The chosen option.
		 * @return {Promise<void>}
		 */
		async choose(option) {
			if (!option) {
				return
			}
			this.errors = []
			this.done = false
			this.busy = true
			try {
				const { data } = await axios.put(generateUrl(TYPE_URL), { type: option.id })
				this.apply(data)
			} catch (err) {
				this.errors = [err.response?.data?.error || err.message]
			} finally {
				this.busy = false
			}
		},

		/**
		 * Hide every checked category, one choice each; refusals are listed and the rest still hidden.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
		 * @return {Promise<void>}
		 */
		async hideChecked() {
			this.errors = []
			this.done = false
			this.busy = true
			const refused = []
			for (const uri of this.checked) {
				try {
					await axios.put(generateUrl(CHOICES_URL), {
						scheme: SCHEME,
						entry: uri,
						choice: { hidden: true },
					})
				} catch (err) {
					const reason = err.response?.data?.error || err.message
					refused.push((this.labels[uri] || uri) + ': ' + reason)
				}
			}
			this.errors = refused
			this.done = refused.length === 0
			this.busy = false
			this.$emit('hidden')
		},

		/**
		 * Check or uncheck a proposed category.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
		 * @param {string} uri The category's URI.
		 * @param {boolean} on Whether it is checked.
		 * @return {void}
		 */
		toggle(uri, on) {
			this.checked = on === true
				? [...new Set([...this.checked, uri])]
				: this.checked.filter((checked) => checked !== uri)
		},

		/**
		 * Keep the answer and check every proposed category.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
		 * @param {object} data The answer: `{type, types, proposal}`.
		 * @return {void}
		 */
		apply(data) {
			this.type = data?.type || null
			this.types = data?.types || []
			this.proposal = data?.proposal || []
			this.checked = this.proposal.map((item) => item.tooiUri)
		},

		/**
		 * The label of a proposed category, its code when the list is not loaded.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
		 * @param {object} item The proposed category.
		 * @return {string} The label.
		 */
		labelOf(item) {
			return this.labels[item.tooiUri] || item.category
		},
	},
}

/**
 * The label of each value-list entry by its URI.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 * @param {Array<object>} entries The entries: `{uri, label}`.
 * @return {object} Labels by URI.
 */
export function labelsByUri(entries) {
	return Object.fromEntries(entries.map((entry) => [entry.uri, entry.label]))
}
</script>

<style scoped>
.organisation-type__heading {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.organisation-type__hint,
.organisation-type__basis {
	color: var(--color-text-maxcontrast);
}

.organisation-type__list {
	list-style: none;
	padding: 0;
}

.organisation-type__basis {
	margin: 0 0 calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 9);
}
</style>
