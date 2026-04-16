<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deletePageContent'"
		ref="dialogRef"
		name="Delete content"
		:can-close="false"
		@close="closeDialog">
		<div>
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>Content successfully deleted</p>
				</NcNoteCard>
				<NcNoteCard v-if="!success" type="error">
					<p>Something went wrong while deleting content</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<p v-if="success === null">
				Do you want to delete this content item? This action cannot be undone.
			</p>

			<span class="modalActions">
				<NcButton @click="navigationStore.setDialog(false)">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton v-if="success === null"
					:disabled="loading"
					type="error"
					@click="handleDelete">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Delete v-if="!loading" :size="20" />
					</template>
					Delete
				</NcButton>
			</span>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import _ from 'lodash'
import { Page } from '../../entities/index.js'

export default {
	name: 'DeletePageContentDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		Cancel,
		Delete,
	},
	data() {
		return {
			loading: false,
			success: null,
			error: null,
		}
	},
	computed: {
		pageItem() {
			return objectStore.getActiveObject('page')
		},
		contentItem() {
			return objectStore.getActiveObject('pageContent')
		},
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			objectStore.setState('page', { success: null, error: null })
			objectStore.clearActiveObject('pageContent')
		},
		async handleDelete() {
			this.loading = true
			this.success = null
			this.error = null

			try {
				const clone = _.cloneDeep(this.pageItem)
				clone.contents = (clone.contents || []).filter(c => c.id !== this.contentItem.id)

				const newPage = new Page(clone)
				await objectStore.updateObject('page', this.pageItem.id, newPage)
				this.success = true
				setTimeout(() => {
					this.closeDialog()
				}, 2000)
			} catch (e) {
				this.error = e?.message || 'Unknown error'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
</style>
