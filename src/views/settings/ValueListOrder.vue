<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section class="value-list-order" :data-testid="'value-list-order-' + scheme">
		<h3 class="value-list-order__heading">
			{{ heading }}
		</h3>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="choicesUnavailable" type="warning">
			{{
				t(
					'opencatalogi',
					'Your own order could not be loaded, so the list is shown in its own order.',
				)
			}}
		</NcNoteCard>
		<p class="hidden-visually" aria-live="polite">
			{{ announcement }}
		</p>
		<ol class="value-list-order__list">
			<li
				v-for="(entry, index) in entries"
				:key="entry.uri"
				class="value-list-order__row">
				<span class="value-list-order__label">{{ entry.label }}</span>
				<ValueListMoveButtons
					:label="entry.label"
					:index="index"
					:count="entries.length"
					:disabled="reordering"
					testidPrefix="value-list"
					:testidKey="scheme + '-' + index"
					@move="move(index, $event)" />
			</li>
		</ol>
	</section>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import ValueListMoveButtons from './ValueListMoveButtons.vue'
import { fetchList, moveInList } from './valueListOrder.js'

/**
 * Move up and Move down for one value list in the Woo settings (REQ-WVL-002).
 *
 * The information categories have their own section with explanations
 * (CategoryExplanations); this section orders any other list, such as
 * soort handeling and documentsoorten, through the same value-list choices:
 * the order is the organisation's own choice beside the national entry.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 */
export default {
	name: 'ValueListOrder',

	components: { NcNoteCard, ValueListMoveButtons },

	props: {
		/** The list, one of the value-list schemes (e.g. `soortHandeling`). */
		scheme: { type: String, required: true },
		/** The section heading, already translated. */
		heading: { type: String, required: true },
	},

	data() {
		return {
			entries: [],
			choicesUnavailable: false,
			error: null,
			reordering: false,
			announcement: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the list in the organisation's order.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
		 * @return {Promise<void>}
		 */
		async load() {
			this.error = null
			try {
				this.apply(await fetchList(this.scheme))
			} catch (err) {
				this.entries = []
				this.error = err.response?.data?.error || err.message
			}
		},

		/**
		 * Move an entry one place up (-1) or down (1), save, and announce its new place.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
		 * @param {number} index The entry's place.
		 * @param {number} delta -1 for up, 1 for down.
		 * @return {Promise<void>}
		 */
		async move(index, delta) {
			await moveInList(this, this.scheme, index, delta)
		},

		/**
		 * Keep the list the server answered.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
		 * @param {object} data The answer: `{entries, choicesUnavailable}`.
		 * @return {void}
		 */
		apply(data) {
			this.entries = data?.entries || []
			this.choicesUnavailable = data?.choicesUnavailable === true
		},
	},
}
</script>

<style scoped>
.value-list-order__heading {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.value-list-order__list {
	padding: 0;
	list-style-position: inside;
}

.value-list-order__row {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: var(--default-grid-baseline);
}

.value-list-order__label {
	flex: 1;
}
</style>
