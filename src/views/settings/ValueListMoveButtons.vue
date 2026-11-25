<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<span class="value-list-move-buttons">
		<NcButton
			:aria-label="t('opencatalogi', 'Move {label} up', { label })"
			:disabled="disabled || index === 0"
			:data-testid="testidPrefix + '-move-up-' + testidKey"
			@click="$emit('move', -1)">
			{{ t('opencatalogi', 'Move up') }}
		</NcButton>
		<NcButton
			:aria-label="t('opencatalogi', 'Move {label} down', { label })"
			:disabled="disabled || index === count - 1"
			:data-testid="testidPrefix + '-move-down-' + testidKey"
			@click="$emit('move', 1)">
			{{ t('opencatalogi', 'Move down') }}
		</NcButton>
	</span>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

/**
 * Move up and Move down for one entry of an ordered value list (REQ-WVL-002).
 *
 * Emits `move` with -1 or 1; the parent saves the order and announces it.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
 */
export default {
	name: 'ValueListMoveButtons',

	components: { NcButton },

	props: {
		/** The entry's label, for the buttons' accessible names. */
		label: { type: String, required: true },
		/** The entry's place in the list. */
		index: { type: Number, required: true },
		/** How many entries the list has. */
		count: { type: Number, required: true },
		/** Whether a move is being saved. */
		disabled: { type: Boolean, default: false },
		/** The first part of the buttons' test ids, e.g. `category`. */
		testidPrefix: { type: String, required: true },
		/** The last part of the buttons' test ids, e.g. the entry's code. */
		testidKey: { type: String, required: true },
	},

	emits: ['move'],
}
</script>

<style scoped>
.value-list-move-buttons {
	display: inline-flex;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
