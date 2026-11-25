<!--
  - SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
/**
 * Component that contains all modals used in the application
 */
import { defineAsyncComponent } from 'vue'
import CatalogModal from './catalog/CatalogModal.vue'
import AddDirectoryModal from './directory/AddDirectoryModal.vue'
import ViewDirectoryModal from './directory/ViewDirectoryModal.vue'
import ViewGlossaryModal from './glossary/ViewGlossaryModal.vue'
import LockObject from './object/LockObject.vue'
import ViewThemeModal from './theme/ViewThemeModal.vue'
import { navigationStore } from './../store/store.js'

// Modals that render only while open load on demand: they carry CodeMirror,
// the markdown editor and the file pickers, which every page used to parse at
// boot although most visits never open one.
const EditAttachmentModal = defineAsyncComponent(() => import('./attachment/EditAttachmentModal.vue'))
const EditListingModal = defineAsyncComponent(() => import('./directory/EditListingModal.vue'))
const UploadFilesModal = defineAsyncComponent(() => import('./generic/UploadFiles.vue'))
const ObjectModal = defineAsyncComponent(() => import('./object/ObjectModal.vue'))
const ViewObject = defineAsyncComponent(() => import('./object/ViewObject.vue'))
const MergeObject = defineAsyncComponent(() => import('./object/MergeObject.vue'))
const UploadObject = defineAsyncComponent(() => import('./object/UploadObject.vue'))
const DownloadObject = defineAsyncComponent(() => import('./object/DownloadObject.vue'))
const MigrationObject = defineAsyncComponent(() => import('./object/MigrationObject.vue'))
const MassDeleteObject = defineAsyncComponent(() => import('./object/MassDeleteObject.vue'))
const MassPublishObjects = defineAsyncComponent(() => import('./object/MassPublishObjects.vue'))
const MassDepublishObjects = defineAsyncComponent(() => import('./object/MassDepublishObjects.vue'))
const MassValidateObjects = defineAsyncComponent(() => import('./object/MassValidateObjects.vue'))
const MassLockObjects = defineAsyncComponent(() => import('./object/MassLockObjects.vue'))
const MassUnlockObjects = defineAsyncComponent(() => import('./object/MassUnlockObjects.vue'))
const MassAttachmentModal = defineAsyncComponent(() => import('../dialogs/attachment/MassAttachmentModal.vue'))
const AddPublicationThemeModal = defineAsyncComponent(() => import('./theme/AddPublicationThemeModal.vue'))
</script>

<template>
	<!-- Placeholder Div -->
	<div>
		<!--Generic-->
		<CatalogModal />
		<AddPublicationThemeModal
			v-if="navigationStore.modal === 'addPublicationTheme'" />
		<!--View Modals-->
		<ViewDirectoryModal />
		<ViewGlossaryModal />
		<ViewThemeModal />
		<!--Specific-->
		<EditAttachmentModal v-if="navigationStore.modal === 'editAttachment'" />
		<AddDirectoryModal />
		<EditListingModal v-if="navigationStore.modal === 'editListing'" />
		<!-- Menu -->
		<UploadFilesModal v-if="navigationStore.modal === 'uploadFiles'" />
		<ObjectModal v-if="navigationStore.modal === 'objectModal'" />
		<!-- Object Modals -->
		<ViewObject v-if="navigationStore.modal === 'viewObject'" />

		<MergeObject v-if="navigationStore.modal === 'mergeObject'" />
		<UploadObject v-if="navigationStore.modal === 'uploadObject'" />
		<DownloadObject v-if="navigationStore.modal === 'downloadObject'" />
		<LockObject />
		<MigrationObject v-if="navigationStore.modal === 'migrationObject'" />
		<!-- Mass Action Modals -->
		<MassDeleteObject v-if="navigationStore.dialog === 'massDeleteObject'" />
		<MassPublishObjects v-if="navigationStore.dialog === 'massPublishObjects'" />
		<MassDepublishObjects
			v-if="navigationStore.dialog === 'massDepublishObjects'" />
		<MassValidateObjects
			v-if="navigationStore.dialog === 'massValidateObjects'" />
		<MassLockObjects v-if="navigationStore.dialog === 'massLockObjects'" />
		<MassUnlockObjects v-if="navigationStore.dialog === 'massUnlockObjects'" />
		<MassAttachmentModal v-if="navigationStore.dialog === 'massAttachment'" />
	</div>
</template>
