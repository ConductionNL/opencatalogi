<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="value-list-order" :data-testid="'value-list-order-' + scheme">
		<h3 class="value-list-order__heading">
			{{ heading }}
		</h3>
		<NcNoteCard
			v-if="error"
			type="error"
			:data-testid="'value-list-order-error-' + scheme">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard
			v-if="choicesUnavailable"
			type="warning"
			:data-testid="'value-list-order-unavailable-' + scheme">
			{{
				t(
					'opencatalogi',
					'Your own order could not be loaded, so the list is shown in its own order.',
				)
			}}
		</NcNoteCard>
		<p
			class="hidden-visually"
			aria-live="polite"
			:data-testid="'value-list-order-announcement-' + scheme">
			{{ announcement }}
		</p>
		<ol class="value-list-order__list">
			<li
				v-for="(entry, index) in entries"
				:key="entry.uri"
				class="value-list-order__row"
				:data-testid="'value-list-entry-' + scheme + '-' + index">
				<span class="value-list-order__label">{{ entry.label }}</span>
				<NcButton
					:aria-label="
						t('opencatalogi', 'Move {label} up', { label: entry.label })
					"
					:disabled="reordering || index === 0"
					:data-testid="'value-list-move-up-' + scheme + '-' + index"
					@click="move(index, -1)">
					{{ t('opencatalogi', 'Move up') }}
				</NcButton>
				<NcButton
					:aria-label="
						t('opencatalogi', 'Move {label} down', {
							label: entry.label,
						})
					"
					:disabled="reordering || index === entries.length - 1"
					:data-testid="'value-list-move-down-' + scheme + '-' + index"
					@click="move(index, 1)">
					{{ t('opencatalogi', 'Move down') }}
				</NcButton>
			</li>
		</ol>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { CHOICES_URL, ORDER_URL, moveEntry } from './CategoryExplanations.vue'

/**
 * Move up and Move down for one value list in the Woo settings (REQ-WVL-002).
 *
 * The information categories have their own section with explanations
 * ({@link CategoryExplanations}); this section orders any other list, such as
 * soort handeling and documentsoorten, through the same value-list choices:
 * the order is the organisation's own choice beside the national entry.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 */
export default {
	name: 'ValueListOrder',

	components: { NcButton, NcNoteCard },

	props: {
		/**
		 * The list, one of the value-list schemes (e.g. `soortHandeling`).
		 */
		scheme: {
			type: String,
			required: true,
		},
		/**
		 * The section heading, already translated.
		 */
		heading: {
			type: String,
			required: true,
		},
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
				const { data } = await axios.get(generateUrl(CHOICES_URL), {
					params: { scheme: this.scheme },
				})
				this.apply(data)
			} catch (err) {
				this.entries = []
				this.error = err.response?.data?.error || err.message
			}
		},

		/**
		 * Move an entry one place up (-1) or down (1) and save the new order.
		 *
		 * The live region says where the entry is now, so the move is
		 * announced to a keyboard or screen-reader user.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
		 * @param {number} index The entry's place.
		 * @param {number} delta -1 for up, 1 for down.
		 * @return {Promise<void>}
		 */
		async move(index, delta) {
			const moved = moveEntry(this.entries, index, delta)
			if (moved === null) {
				return
			}
			this.error = null
			this.reordering = true
			const entry = this.entries[index]
			try {
				const { data } = await axios.put(generateUrl(ORDER_URL), {
					scheme: this.scheme,
					entries: moved.map((item) => item.uri),
				})
				this.apply(data)
				this.announcement = t(
					'opencatalogi',
					'{label} is now at position {position}.',
					{
						label: entry.label,
						position: index + delta + 1,
					},
				)
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.reordering = false
			}
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
