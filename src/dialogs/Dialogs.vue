<script setup>
import { navigationStore } from './../store/store.js'
</script>

<template>
	<!-- Placeholder div -->
	<div>
		<DeleteObjectDialog />
		<CopyObjectDialog />
		<DeleteListingDialog />
		<ViewLogDialog />
		<DeleteAttachmentDialog v-if="navigationStore.dialog === 'deleteAttachment'" />
		<UploadFiles />
	</div>
</template>

<script>
import DeleteObjectDialog from './generic/DeleteObjectDialog.vue'
import CopyObjectDialog from './generic/CopyObjectDialog.vue'
import DeleteListingDialog from './listing/DeleteListingDialog.vue'
import ViewLogDialog from './logs/ViewLogDialog.vue'
import DeleteAttachmentDialog from './attachment/DeleteAttachmentDialog.vue'
import UploadFiles from '../modals/generic/UploadFiles.vue'

export default {
	name: 'Dialogs',
	components: {
		DeleteObjectDialog,
		CopyObjectDialog,
		DeleteListingDialog,
		ViewLogDialog,
		DeleteAttachmentDialog,
	},
}
</script>
