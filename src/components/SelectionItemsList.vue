<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="selected-objects-container">
		<h4 v-if="!hideTitle">{{ title }} ({{ items.length }})</h4>

		<div v-if="items.length" class="selected-objects-list">
			<TransitionGroup name="list" tag="div">
				<div
					v-for="item in items"
					:key="item.id"
					class="selected-object-item"
					:class="{
						'has-error': itemError(item),
						'is-disabled': itemDisabled(item),
					}"
					:title="itemDisabledReason(item) || undefined">
					<div class="object-info">
						<strong>{{ itemName(item) }}</strong>
						<p class="object-schema">
							{{ itemSubtitle(item) }}
						</p>
						<p v-if="itemError(item)" class="object-error">
							<AlertCircle :size="16" />
							{{ itemError(item) }}
						</p>
					</div>
					<NcButton
						v-if="showRemove"
						variant="tertiary"
						:aria-label="
							t('opencatalogi', 'Remove {name}', {
								name: itemName(item),
							})
						"
						@click="$emit('remove', item)">
						<template #icon>
							<Close :size="20" />
						</template>
					</NcButton>
				</div>
			</TransitionGroup>
		</div>

		<NcEmptyContent v-else :name="emptyTitle">
			<template #description>
				{{ emptyDescription }}
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Close from 'vue-material-design-icons/Close.vue'

/**
 * The markup of a selection list: a counted title, one row per selected item
 * (name, subtitle, error, disabled state and a remove button) or an empty
 * state. The list that uses it decides what each item says and what removing
 * one does.
 *
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'SelectionItemsList',
	components: {
		NcButton,
		NcEmptyContent,
		Close,
		AlertCircle,
	},

	props: {
		/** The items to list, each with an `id`. */
		items: {
			type: Array,
			required: true,
		},

		/** The heading shown above the list, followed by the item count. */
		title: {
			type: String,
			required: true,
		},

		/** Whether to leave the heading out. */
		hideTitle: {
			type: Boolean,
			default: false,
		},

		/** The empty state's title. */
		emptyTitle: {
			type: String,
			required: true,
		},

		/** The empty state's description. */
		emptyDescription: {
			type: String,
			required: true,
		},

		/** Whether each row has a remove button. */
		showRemove: {
			type: Boolean,
			required: true,
		},

		/** The name an item is shown with. */
		itemName: {
			type: Function,
			required: true,
		},

		/** The line under an item's name. */
		itemSubtitle: {
			type: Function,
			required: true,
		},

		/** The error an item carries, if any. */
		itemError: {
			type: Function,
			required: true,
		},

		/** Whether an item renders as disabled. */
		itemDisabled: {
			type: Function,
			default: () => false,
		},

		/** Why an item is disabled, shown as its tooltip. */
		itemDisabledReason: {
			type: Function,
			default: () => null,
		},
	},

	emits: ['remove'],
}
</script>

<style scoped>
.selected-objects-container {
	margin: 20px 0;
}

.selected-objects-list {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	transition: all 0.3s ease;
}

.selected-object-item:last-child {
	border-bottom: none;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-schema {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.object-error {
	color: var(--color-error);
	font-size: 0.85em;
	margin: 4px 0 0 0;
	display: flex;
	align-items: center;
	gap: 4px;
}

.selected-object-item.has-error {
	border-inline-start: 3px solid var(--color-error);
	background-color: var(--color-background-dark);
}

.selected-object-item.is-disabled {
	opacity: 0.5;
}

/* Transition animations for list items */
.list-move,
.list-enter-active,
.list-leave-active {
	transition: all 0.3s ease;
}

.list-enter-from,
.list-leave-to {
	opacity: 0;
	transform: translateX(30px);
}

.list-leave-active {
	position: absolute;
	inset-inline: 0;
}

/* WCAG 2.3.3. Two decorative motions here: the per-item hover transition, and
   the <transition-group> slide that moves items 30px horizontally as they are
   added or removed. The slide is the vestibular trigger, so the transform is
   dropped as well as the tween — items still fade via opacity, so appearance
   and removal remain perceivable. */
@media (prefers-reduced-motion: reduce) {
	.selected-object-item,
	.list-move,
	.list-enter-active,
	.list-leave-active {
		transition: none;
	}

	.list-enter-from,
	.list-leave-to {
		transform: none;
	}
}
</style>
