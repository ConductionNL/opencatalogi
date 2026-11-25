<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="category-explanations" data-testid="category-explanations">
		<h3 class="category-explanations__heading">
			{{ t('opencatalogi', 'Category explanations') }}
		</h3>
		<p class="category-explanations__hint">
			{{
				t(
					'opencatalogi',
					'Explain an information category in your own words. The portal shows the explanation where a citizen chooses the category filter.',
				)
			}}
		</p>
		<NcNoteCard
			v-if="error"
			type="error"
			data-testid="category-explanations-error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard
			v-if="choicesUnavailable"
			type="warning"
			data-testid="category-explanations-unavailable">
			{{
				t(
					'opencatalogi',
					'Your own choices could not be loaded, so the categories are shown in the order of the list itself.',
				)
			}}
		</NcNoteCard>
		<ul class="category-explanations__list">
			<li
				v-for="entry in entries"
				:key="entry.uri"
				class="category-explanations__row"
				:data-testid="'category-explanation-' + entry.code">
				<NcTextArea
					:modelValue="draftOf(entry)"
					:label="entry.label"
					:maxlength="maxLength"
					:disabled="saving === entry.uri"
					rows="2"
					@update:modelValue="setDraft(entry, $event)" />
				<NcButton
					:disabled="
						saving === entry.uri || draftOf(entry) === entry.explanation
					"
					@click="save(entry)">
					{{ t('opencatalogi', 'Save explanation') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcTextArea } from '@nextcloud/vue'

/**
 * The URL of the value-list choices of the information categories.
 */
export const CHOICES_URL = '/apps/opencatalogi/api/woo/value-list-choices'

/**
 * The list the information categories are.
 */
export const SCHEME = 'informatiecategorieen'

/**
 * The administrator writes an explanation per information category (REQ-WVL-003).
 *
 * The explanation is the organisation's own text beside the national entry:
 * it is saved as a value-list choice, and `GET /api/woo/categories/public`
 * serves it to the portal's category filter.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
 */
export default {
	name: 'CategoryExplanations',

	components: { NcButton, NcNoteCard, NcTextArea },

	data() {
		return {
			entries: [],
			drafts: {},
			choicesUnavailable: false,
			error: null,
			saving: null,
			maxLength: 1000,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the categories with the organisation's choices.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
		 * @return {Promise<void>}
		 */
		async load() {
			this.error = null
			try {
				const { data } = await axios.get(generateUrl(CHOICES_URL), {
					params: { scheme: SCHEME },
				})
				this.apply(data)
			} catch (err) {
				this.entries = []
				this.error = err.response?.data?.error || err.message
			}
		},

		/**
		 * Save the explanation of one category.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
		 * @param {object} entry The category.
		 * @return {Promise<void>}
		 */
		async save(entry) {
			this.error = null
			this.saving = entry.uri
			try {
				const { data } = await axios.put(generateUrl(CHOICES_URL), {
					scheme: SCHEME,
					entry: entry.uri,
					choice: { explanation: this.draftOf(entry) },
				})
				this.apply(data)
				showSuccess(t('opencatalogi', 'Explanation saved'))
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.saving = null
			}
		},

		/**
		 * Keep the list the server answered and drop the drafts it now holds.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
		 * @param {object} data The answer: `{entries, choicesUnavailable}`.
		 * @return {void}
		 */
		apply(data) {
			this.entries = data?.entries || []
			this.choicesUnavailable = data?.choicesUnavailable === true
			this.drafts = {}
		},

		/**
		 * The explanation being edited for a category.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
		 * @param {object} entry The category.
		 * @return {string} The draft, or the saved explanation.
		 */
		draftOf(entry) {
			return draftOf(this.drafts, entry)
		},

		/**
		 * Change the draft of a category.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
		 * @param {object} entry The category.
		 * @param {string} value The new text.
		 * @return {void}
		 */
		setDraft(entry, value) {
			this.drafts = { ...this.drafts, [entry.uri]: value }
		},
	},
}

/**
 * The draft explanation of an entry, or its saved one when it has no draft.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
 * @param {object} drafts The drafts by entry URI.
 * @param {object} entry The entry.
 * @return {string} The text.
 */
export function draftOf(drafts, entry) {
	return Object.hasOwn(drafts, entry.uri)
		? drafts[entry.uri]
		: entry.explanation || ''
}
</script>

<style scoped>
.category-explanations__heading {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.category-explanations__hint {
	color: var(--color-text-maxcontrast);
}

.category-explanations__list {
	list-style: none;
	padding: 0;
}

.category-explanations__row {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}
</style>
