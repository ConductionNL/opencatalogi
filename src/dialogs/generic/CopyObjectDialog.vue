<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { generateUrl } from '@nextcloud/router'
import { computed, ref, watch } from 'vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import ConfirmObjectActionDialog from './ConfirmObjectActionDialog.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

const dialogProperties = computed(() => navigationStore.dialogProperties)

const objectType = computed(() => dialogProperties.value?.objectType)
const dialogTitle = computed(() => dialogProperties.value?.dialogTitle)
const isMultiple = computed(() => dialogProperties.value?.isMultiple ?? false)

// Check if this dialog should be shown
const shouldShowDialog = computed(() => navigationStore.dialog === 'copyObject')

const activeObject = computed(() =>
	objectType.value ? objectStore.getActiveObject(objectType.value) : null,
)

const sourceSchemaId = computed(() => {
	const id = activeObject.value?.['@self']?.schema
	return id !== undefined && id !== null ? String(id) : null
})

// Display name shown in the dialog. The backend already resolves the schema's
// configured objectNameField into @self.name on read, so we only need to fall
// back to top-level fields and finally the id.
const displayName = computed(() => {
	const obj = activeObject.value
	if (!obj) return ''
	return (
		obj['@self']?.name
		|| obj.name
		|| obj.title
		|| obj['@self']?.id
		|| obj.id
		|| ''
	)
})

// Cache for the source object's schema, keyed by schema id. Loaded only so
// copyObject can prefix "Kopie van" onto the field configured by the schema's
// objectNameField (which may be a nested path like "contact.naam").
const schemasById = ref({})

// Fetch the source schema when the single-object dialog opens so the rename
// step can target the right field. Skipped for multi-select (no rename UI).
watch(
	[shouldShowDialog, sourceSchemaId, isMultiple],
	async ([shown, schemaId, multiple]) => {
		if (!shown || multiple || !schemaId || schemasById.value[schemaId]) return
		try {
			const response = await fetch(
				generateUrl(`/apps/openregister/api/schemas/${schemaId}`),
				{ method: 'GET', headers: { 'Content-Type': 'application/json' } },
			)
			if (!response.ok) return
			schemasById.value = {
				...schemasById.value,
				[schemaId]: await response.json(),
			}
		} catch {
			// Silent failure — copyObject falls back to title/name.
		}
	},
	{ immediate: true },
)

/**
 * Refresh the listing for the given object type. Publications are loaded via
 * the catalog-aware endpoint, so they need a different refresh path than the
 * generic objectStore.fetchCollection.
 *
 * @param {string} type - Object type
 * @return {void}
 */
function refreshObjectList(type) {
	if (type === 'publication') {
		catalogStore.fetchPublications()
	} else {
		objectStore.fetchCollection(type)
	}
}

/**
 * The schema's configured objectNameField path for the source object, if any.
 * Forwarded to copyObject so the "Kopie van" prefix is applied to the right
 * field for schemas that don't use title/name.
 */
const nameFieldPath = computed(() => {
	const schemaId = sourceSchemaId.value
	const schema = schemaId ? schemasById.value[schemaId] : null
	const path = schema?.configuration?.objectNameField
	return typeof path === 'string' && path.length > 0 ? path : null
})

/**
 * Copy the object(s)
 *
 * @return {void}
 */
function copyObject() {
	if (isMultiple.value) {
		const selectedObjects = objectStore.getSelectedObjects(objectType.value)
		if (!selectedObjects?.length) return

		Promise.all(
			selectedObjects.map((obj) =>
				objectStore.copyObject(
					objectType.value,
					obj.id,
					nameFieldPath.value,
				),
			),
		).then(() => {
			refreshObjectList(objectType.value)
			closeDialog()
		})
	} else {
		const activeObject = objectStore.getActiveObject(objectType.value)
		if (!activeObject?.id) return

		objectStore
			.copyObject(objectType.value, activeObject.id, nameFieldPath.value)
			.then(() => {
				refreshObjectList(objectType.value)
				closeDialog()
			})
	}
}

/**
 * Close the dialog after a delay
 *
 * @return {void}
 */
function closeDialog() {
	setTimeout(() => {
		objectStore.setState(objectType.value, { success: null, error: null })
		navigationStore.setDialog(false)
	}, 2000)
}
</script>

<template>
	<ConfirmObjectActionDialog
		v-if="shouldShowDialog"
		dialog="copyObject"
		:objectType="objectType"
		:canClose="false"
		:title="
			isMultiple
				? t('opencatalogi', 'Copy {type}s', { type: dialogTitle })
				: t('opencatalogi', 'Copy {type}', { type: dialogTitle })
		"
		:actionLabel="t('opencatalogi', 'Copy')"
		:actionIcon="ContentCopy"
		actionIconName="ContentCopy"
		actionVariant="primary"
		@confirm="copyObject">
		<template #success>
			{{
				isMultiple
					? t('opencatalogi', '{type}s successfully copied', {
							type: dialogTitle,
						})
					: t('opencatalogi', '{type} successfully copied', {
							type: dialogTitle,
						})
			}}
		</template>
		<template #failure>
			{{
				isMultiple
					? t('opencatalogi', 'Something went wrong while copying {type}s', {
							type: dialogTitle.toLowerCase(),
						})
					: t('opencatalogi', 'Something went wrong while copying {type}', {
							type: dialogTitle.toLowerCase(),
						})
			}}
		</template>
		<template #loading>
			{{
				isMultiple
					? t('opencatalogi', '{type}s are being copied...', {
							type: dialogTitle,
						})
					: t('opencatalogi', '{type} is being copied...', {
							type: dialogTitle,
						})
			}}
		</template>
		<template #question>
			<template v-if="isMultiple">
				{{
					t('opencatalogi', 'Do you want to copy the selected {type}s?', {
						type: dialogTitle.toLowerCase(),
					})
				}}
			</template>
			<template v-else>
				{{
					t('opencatalogi', 'Do you want to copy {name}?', {
						name: displayName,
					})
				}}
			</template>
		</template>
	</ConfirmObjectActionDialog>
</template>

<script>
/**
 * Copy Object Dialog Component
 *
 * @module Dialogs
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'CopyObjectDialog',
	components: {
		ConfirmObjectActionDialog,
	},

	methods: {
		/**
		 * Copy the object(s)
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		copyObject() {
			if (this.isMultiple) {
				const selectedObjects = objectStore.getSelectedObjects(
					this.objectType,
				)
				if (!selectedObjects?.length) return

				Promise.all(
					selectedObjects.map((obj) =>
						objectStore.copyObject(this.objectType, obj.id),
					),
				).then(() => {
					this.closeDialog()
				})
			} else {
				const activeObject = objectStore.getActiveObject(this.objectType)
				if (!activeObject?.id) return

				objectStore.copyObject(this.objectType, activeObject.id).then(() => {
					this.closeDialog()
				})
			}
		},

		/**
		 * Close the dialog after a delay
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		closeDialog() {
			setTimeout(() => {
				objectStore.setState(this.objectType, { success: null, error: null })
				navigationStore.setDialog(false)
			}, 2000)
		},
	},
}
</script>
