<script setup>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import { generateUrl } from '@nextcloud/router'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'
import { computed, ref, watch } from 'vue'

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
	return obj['@self']?.name
		|| obj.name
		|| obj.title
		|| obj['@self']?.id
		|| obj.id
		|| ''
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
			schemasById.value = { ...schemasById.value, [schemaId]: await response.json() }
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
const refreshObjectList = (type) => {
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
const copyObject = () => {
	if (isMultiple.value) {
		const selectedObjects = objectStore.getSelectedObjects(objectType.value)
		if (!selectedObjects?.length) return

		Promise.all(selectedObjects.map(obj =>
			objectStore.copyObject(objectType.value, obj.id, nameFieldPath.value),
		))
			.then(() => {
				refreshObjectList(objectType.value)
				closeDialog()
			})
	} else {
		const activeObject = objectStore.getActiveObject(objectType.value)
		if (!activeObject?.id) return

		objectStore.copyObject(objectType.value, activeObject.id, nameFieldPath.value)
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
const closeDialog = () => {
	setTimeout(() => {
		objectStore.setState(objectType.value, { success: null, error: null })
		navigationStore.setDialog(false)
	}, 2000)
}
</script>

<template>
	<NcDialog
		v-if="shouldShowDialog"
		:name="`${dialogTitle}${isMultiple ? 's' : ''} kopiëren`"
		:can-close="false">
		<div v-if="objectStore.getState(objectType).success !== null || objectStore.getState(objectType).error">
			<NcNoteCard v-if="objectStore.getState(objectType).success" type="success">
				<p>{{ dialogTitle }}{{ isMultiple ? 's' : '' }} successfully copied</p>
			</NcNoteCard>
			<NcNoteCard v-if="!objectStore.getState(objectType).success" type="error">
				<p>Something went wrong while copying {{ dialogTitle.toLowerCase() }}{{ isMultiple ? 's' : '' }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="objectStore.getState(objectType).error" type="error">
				<p>{{ objectStore.getState(objectType).error }}</p>
			</NcNoteCard>
		</div>
		<div v-if="objectStore.isLoading(objectType)" class="loading-status">
			<NcLoadingIcon :size="20" />
			<span>{{ dialogTitle }}{{ isMultiple ? 's' : '' }} {{ isMultiple ? 'worden' : 'wordt' }} gekopieerd...</span>
		</div>
		<p v-if="objectStore.getState(objectType).success === null && !objectStore.isLoading(objectType)">
			<template v-if="isMultiple">
				Do you want to copy the selected {{ dialogTitle.toLowerCase() }}s?
			</template>
			<template v-else>
				Do you want to copy <b>{{ displayName }}</b>?
			</template>
		</p>
		<template v-if="objectStore.getState(objectType).success === null && !objectStore.isLoading(objectType)" #actions>
			<NcButton
				:disabled="objectStore.isLoading(objectType)"
				icon=""
				@click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="objectStore.isLoading(objectType)"
				icon="ContentCopy"
				type="primary"
				@click="copyObject">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				Copy
			</NcButton>
		</template>
		<template v-else #actions>
			<NcButton
				icon=""
				@click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * Copy Object Dialog Component
 * @module Dialogs
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */
export default {
	name: 'CopyObjectDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		// Icons
		Cancel,
		ContentCopy,
	},
	methods: {
		/**
		 * Copy the object(s)
		 *
		 * @return {void}
		 */
		copyObject() {
			if (this.isMultiple) {
				const selectedObjects = objectStore.getSelectedObjects(this.objectType)
				if (!selectedObjects?.length) return

				Promise.all(selectedObjects.map(obj =>
					objectStore.copyObject(this.objectType, obj.id),
				))
					.then(() => {
						this.closeDialog()
					})
			} else {
				const activeObject = objectStore.getActiveObject(this.objectType)
				if (!activeObject?.id) return

				objectStore.copyObject(this.objectType, activeObject.id)
					.then(() => {
						this.closeDialog()
					})
			}
		},
		/**
		 * Close the dialog after a delay
		 *
		 * @return {void}
		 */
		closeDialog() {
			setTimeout(() => {
				objectStore.setState(this.objectType, { success: null, error: null })
				navigationStore.setDialog(false)
			}, 2000)
		},
	},
}
</script>

<style>
.zaakDetailsContainer {
    margin-block-start: var(--OC-margin-20);
    margin-inline-start: var(--OC-margin-20);
    margin-inline-end: var(--OC-margin-20);
}

.success {
    color: green;
}

.loading-status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin: 1rem 0;
    color: var(--color-text-lighter);
}
</style>
