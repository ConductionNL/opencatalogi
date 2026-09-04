<script setup>
import { navigationStore } from './../store/store.js'
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
		<DownloadObject />
		<LockObject />
		<MigrationObject v-if="navigationStore.modal === 'migrationObject'" />
		<!-- Mass Action Modals -->
		<MassDeleteObject v-if="navigationStore.dialog === 'massDeleteObject'" />
		<MassPublishObjects v-if="navigationStore.dialog === 'massPublishObjects'" />
		<MassDepublishObjects
			v-if="navigationStore.dialog === 'massDepublishObjects'" />
		<MassValidateObjects
			v-if="navigationStore.dialog === 'massValidateObjects'" />
		<MassAttachmentModal v-if="navigationStore.dialog === 'massAttachment'" />
	</div>
</template>

<script>
import MassAttachmentModal from '../dialogs/attachment/MassAttachmentModal.vue'
// Specific
import EditAttachmentModal from './attachment/EditAttachmentModal.vue'
// Generic
import CatalogModal from './catalog/CatalogModal.vue'
import AddDirectoryModal from './directory/AddDirectoryModal.vue'
import EditListingModal from './directory/EditListingModal.vue'
// View Modals
import ViewDirectoryModal from './directory/ViewDirectoryModal.vue'
import UploadFilesModal from './generic/UploadFiles.vue'
import ViewGlossaryModal from './glossary/ViewGlossaryModal.vue'
import DownloadObject from './object/DownloadObject.vue'
import LockObject from './object/LockObject.vue'
import MassDeleteObject from './object/MassDeleteObject.vue'
import MassDepublishObjects from './object/MassDepublishObjects.vue'
import MassPublishObjects from './object/MassPublishObjects.vue'
import MassValidateObjects from './object/MassValidateObjects.vue'
import MergeObject from './object/MergeObject.vue'
import MigrationObject from './object/MigrationObject.vue'
import ObjectModal from './object/ObjectModal.vue'
import UploadObject from './object/UploadObject.vue'
// Object Modals
import ViewObject from './object/ViewObject.vue'
// Publication
import AddPublicationThemeModal from './theme/AddPublicationThemeModal.vue'
import ViewThemeModal from './theme/ViewThemeModal.vue'
/**
 * Component that contains all modals used in the application
 */
export default {
	name: 'Modals',
	components: {
		EditAttachmentModal,
		CatalogModal,
		AddDirectoryModal,
		EditListingModal,
		// View Modals
		ViewDirectoryModal,
		ViewGlossaryModal,
		ViewThemeModal,
		UploadFilesModal,
		ObjectModal,
		// Object Modals
		ViewObject,
		MergeObject,
		UploadObject,
		DownloadObject,
		LockObject,
		MigrationObject,
		MassDeleteObject,
		MassPublishObjects,
		MassDepublishObjects,
		MassValidateObjects,
		AddPublicationThemeModal,
		MassAttachmentModal,
	},
}
</script>
