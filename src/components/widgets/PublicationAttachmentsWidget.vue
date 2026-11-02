<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publication-attachments" data-testid="publication-attachments">
		<CnFilesCard
			v-if="resolvedObjectId"
			:key="refreshKey"
			:register="resolvedRegister"
			:schema="resolvedSchema"
			:objectId="resolvedObjectId"
			surface="detail-page"
			:title="cardTitle"
			:maxDisplay="content.maxDisplay || 100" />
		<NcButton
			class="publication-attachments__add"
			variant="secondary"
			:disabled="!publication"
			data-testid="publication-attachments-add"
			@click="addAttachment">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('opencatalogi', 'Add attachment') }}
		</NcButton>
	</div>
</template>

<script>
import { CnFilesCard } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { navigationStore, objectStore } from '../../store/store.js'

/**
 * PublicationAttachmentsWidget: the publication's attachments with an Add
 * attachment button that opens the app's upload dialog (labels, automatic
 * publishing, a progress list), board OcBijlageToevoegen.
 *
 * Lists the files with the library's CnFilesCard. The dialog uploads to the
 * active publication, so Add sets it from the page's object first; when the
 * dialog closes the list is read again.
 *
 * @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001
 */
export default {
	name: 'PublicationAttachmentsWidget',
	components: { CnFilesCard, NcButton, Plus },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},

	props: {
		register: { type: String, default: '' },
		schema: { type: [String, Object], default: '' },
		objectId: { type: String, default: '' },
		content: { type: Object, default: () => ({}) },
		title: { type: String, default: '' },
	},

	data() {
		return {
			/** Bumped to read the files again after an upload. */
			refreshKey: 0,
			/** Whether this widget opened the upload dialog. */
			uploading: false,
		}
	},

	computed: {
		/** @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001 */
		ctx() {
			const injected =
				this.cnObjectContext
				&& (this.cnObjectContext.value || this.cnObjectContext)
			const holder =
				this.cnDetailObjectContext && this.cnDetailObjectContext.value
			return injected || holder || {}
		},

		/** @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001 */
		cardTitle() {
			return (
				this.title || this.content.title || t('opencatalogi', 'Attachments')
			)
		},

		/** @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001 */
		openDialog() {
			return navigationStore.dialog
		},

		/** @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001 */
		publication() {
			return this.ctx.object || null
		},

		/** @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001 */
		resolvedObjectId() {
			return String(
				this.objectId || this.ctx.objectId || this.publication?.id || '',
			)
		},

		/** @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001 */
		resolvedRegister() {
			return String(
				this.register
					|| this.ctx.register
					|| this.publication?.['@self']?.register
					|| '',
			)
		},

		/** @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001 */
		resolvedSchema() {
			const schema =
				this.schema || this.ctx.schema || this.publication?.['@self']?.schema
			return String(
				schema && typeof schema === 'object' ? schema.id : schema || '',
			)
		},
	},

	watch: {
		/**
		 * Read the files again once the upload dialog this widget opened closes.
		 *
		 * @param {string|false|null} dialog The open dialog.
		 * @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001
		 */
		openDialog(dialog) {
			if (this.uploading && dialog !== 'uploadFiles') {
				this.uploading = false
				this.refreshKey += 1
			}
		},
	},

	methods: {
		t,

		/**
		 * Open the upload dialog for this publication.
		 *
		 * @return {void}
		 * @spec openspec/specs/publication-attachment-upload-dialog/spec.md#requirement-the-attachments-card-opens-the-upload-dialog-pau-001
		 */
		addAttachment() {
			if (!this.publication) {
				return
			}
			objectStore.setActiveObject('publication', this.publication)
			this.uploading = true
			navigationStore.setDialog('uploadFiles')
		},
	},
}
</script>

<style scoped>
.publication-attachments {
	position: relative;
	height: 100%;
}

/* The button sits in the card's header row, where the board draws it. */
.publication-attachments__add {
	position: absolute;
	inset-block-start: calc(var(--default-grid-baseline) * 2);
	inset-inline-end: calc(var(--default-grid-baseline) * 4);
}
</style>
