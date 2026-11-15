<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { resolveSelectedObjects } from '../services/selectedObjects.js'
import { objectStore } from '../store/store.js'
</script>

<template>
	<SelectionItemsList
		:items="selectedObjects"
		:title="title"
		:hideTitle="hideTitle"
		:emptyTitle="emptyTitle"
		:emptyDescription="emptyDescription"
		:showRemove="showRemove"
		:itemName="getObjectName"
		:itemSubtitle="getObjectSubtitle"
		:itemError="getObjectError"
		:itemDisabled="isItemDisabled"
		:itemDisabledReason="getDisabledReason"
		@remove="(obj) => removeObject(obj.id || obj['@self']?.id)" />
</template>

<script>
import SelectionItemsList from './SelectionItemsList.vue'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'SelectedObjectsList',
	components: {
		SelectionItemsList,
	},

	props: {
		/**
		 * Title for the selected objects section
		 */
		title: {
			type: String,
			default: 'Selected Publications',
		},

		/**
		 * Title to show when no objects are selected
		 */
		emptyTitle: {
			type: String,
			default: 'No publications selected',
		},

		/**
		 * Description to show when no objects are selected
		 */
		emptyDescription: {
			type: String,
			default: 'No publications are currently selected.',
		},

		/**
		 * Array of objects to display (optional, if not provided uses selected objects from store)
		 */
		objects: {
			type: Array,
			default: null,
		},

		/**
		 * Whether to show remove buttons
		 */
		showRemove: {
			type: Boolean,
			default: true,
		},

		/**
		 * Hide the internal title heading (useful when the parent renders its own header)
		 */
		hideTitle: {
			type: Boolean,
			default: false,
		},

		/**
		 * Which object field to render as the per-item subtitle. Defaults to 'schema'
		 * for backwards compatibility. Set to e.g. 'summary' to show the object summary,
		 * or any other top-level object property.
		 */
		subtitleAttribute: {
			type: String,
			default: 'schema',
		},

		/**
		 * Optional predicate marking individual items as disabled. Disabled
		 * items render at reduced opacity to communicate that they will be
		 * skipped by the parent action. Receives the object and returns a
		 * boolean.
		 */
		isDisabled: {
			type: Function,
			default: null,
		},

		/**
		 * Optional helper returning a tooltip string explaining why an item
		 * is disabled. Receives the object. Only consulted for items where
		 * `isDisabled` returns true.
		 */
		disabledReason: {
			type: Function,
			default: null,
		},
	},

	computed: {
		/**
		 * Get objects to display (either from props or from store)
		 *
		 * @return {Array<object>} Array of publication objects
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7 */
		selectedObjects() {
			return resolveSelectedObjects(this.objects, objectStore)
		},
	},

	methods: {
		/**
		 * Remove object from selected objects in the store
		 *
		 * @param {string|number} objectId - The object ID to remove
		 */
		/**
		 * @param objectId
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		removeObject(objectId) {
			// Always remove from store - the store is the source of truth
			// 1) Remove from selectedObjects (objects flow)
			if (
				Array.isArray(objectStore.selectedObjects)
				&& objectStore.selectedObjects.length > 0
			) {
				const currentSelected = [...objectStore.selectedObjects]
				const index = currentSelected.findIndex(
					(obj) => (obj.id || obj['@self']?.id) === objectId,
				)
				if (index > -1) {
					currentSelected.splice(index, 1)
					objectStore.setSelectedObjects(currentSelected)
				}
			}

			// 2) Remove from selectedAttachments (attachments flow)
			if (
				Array.isArray(objectStore.selectedAttachments)
				&& objectStore.selectedAttachments.length > 0
			) {
				const remaining = objectStore.selectedAttachments.filter(
					(id) => id !== objectId,
				)
				objectStore.setSelectedAttachments(remaining)
			}
		},

		/**
		 * Get display name for an object
		 *
		 * @param {object} obj - The object to get name for
		 * @return {string} The display name
		 */
		/**
		 * @param obj
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		getObjectName(obj) {
			return (
				obj['@self']?.name
				|| obj.name
				|| obj.title
				|| obj['@self']?.title
				|| `Unnamed ${this.title.includes('Publication') ? 'Publication' : 'Object'}`
			)
		},

		/**
		 * Get schema name for an object
		 *
		 * @param {object} obj - The object to get schema for
		 * @return {string} The schema name or fallback text
		 */
		/**
		 * @param obj
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		getObjectSchema(obj) {
			const schema = obj['@self']?.schema || obj.schema

			if (schema && typeof schema === 'object') {
				return schema.title || schema.name || schema.id || 'Unknown Schema'
			}

			if (schema != null && schema !== '') {
				const match = objectStore.availableSchemas.find(
					(s) => Number(s.id) === Number(schema),
				)
				return match?.title || match?.name || String(schema)
			}

			return 'No Schema'
		},

		/**
		 * Get the subtitle text for an object, based on the subtitle-attribute prop.
		 * Defaults to the schema name for backwards compatibility.
		 *
		 * @param {object} obj - The object to get the subtitle for
		 * @return {string} The subtitle text
		 */
		/**
		 * @param obj
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		getObjectSubtitle(obj) {
			if (this.subtitleAttribute === 'schema') {
				return this.getObjectSchema(obj)
			}
			const value =
				obj?.[this.subtitleAttribute]
				?? obj?.['@self']?.[this.subtitleAttribute]
			return value == null || value === '' ? '' : String(value)
		},

		/**
		 * Get error message for an object
		 *
		 * @param {object} obj - The object to get error for
		 * @return {string|null} The error message or null if no error
		 */
		/**
		 * @param obj
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		getObjectError(obj) {
			const objectId = obj.id || obj['@self']?.id
			return objectStore.getObjectError(objectId)
		},

		/**
		 * Whether the item should render in its disabled state.
		 *
		 * @param {object} obj - The object to check.
		 * @return {boolean} true when the parent's `isDisabled` predicate returns true.
		 */
		isItemDisabled(obj) {
			return typeof this.isDisabled === 'function'
				? !!this.isDisabled(obj)
				: false
		},

		/**
		 * Tooltip text for a disabled item, if the parent supplied a reason helper.
		 *
		 * @param {object} obj - The object to inspect.
		 * @return {string|null} The reason or null.
		 */
		/**
		 * @param obj
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		getDisabledReason(obj) {
			if (!this.isItemDisabled(obj)) return null
			if (typeof this.disabledReason !== 'function') return null
			return this.disabledReason(obj) || null
		},
	},
}
</script>
