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
		<DeleteAttachmentDialog
			v-if="navigationStore.dialog === 'deleteAttachment'" />
		<UploadFiles />
		<CaseTypeResyncDialog v-if="navigationStore.dialog === 'caseTypeResync'" />
		<RetentionDecisionDialog
			v-if="navigationStore.dialog === 'retentionDecision'" />
	</div>
</template>

<script>
import UploadFiles from '../modals/generic/UploadFiles.vue'
import DeleteAttachmentDialog from './attachment/DeleteAttachmentDialog.vue'
import CaseTypeResyncDialog from './caseType/CaseTypeResyncDialog.vue'
import CopyObjectDialog from './generic/CopyObjectDialog.vue'
import DeleteObjectDialog from './generic/DeleteObjectDialog.vue'
import DeleteListingDialog from './listing/DeleteListingDialog.vue'
import ViewLogDialog from './logs/ViewLogDialog.vue'
import RetentionDecisionDialog from './retention/RetentionDecisionDialog.vue'

export default {
	name: 'Dialogs',
	components: {
		DeleteObjectDialog,
		CopyObjectDialog,
		DeleteListingDialog,
		ViewLogDialog,
		DeleteAttachmentDialog,
		CaseTypeResyncDialog,
		RetentionDecisionDialog,
	},
}
</script>
