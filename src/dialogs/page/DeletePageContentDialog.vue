<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deletePageContent'"
		ref="dialogRef"
		:name="t('opencatalogi', 'Delete content')"
		:can-close="false"
		@close="closeDialog">
		<div>
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>{{ t('opencatalogi', 'Content successfully deleted') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="!success" type="error">
					<p>{{ t('opencatalogi', 'Something went wrong while deleting content') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<p v-if="success === null">
				{{ t('opencatalogi', 'Do you want to delete this content item? This action cannot be undone.') }}
			</p>

			<span class="modalActions">
				<NcButton @click="navigationStore.setDialog(false)">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
				</NcButton>
				<NcButton v-if="success === null"
					:disabled="loading"
					type="error"
					@click="handleDelete">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Delete v-if="!loading" :size="20" />
					</template>
					{{ t('opencatalogi', 'Delete') }}
				</NcButton>
			</span>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { Page } from '../../entities/index.js'

/**
 * DeletePageContentDialog — remove a content block by updating the parent page.
 *
 * @spec openspec/changes/retrofit-2026-05-25-content-management/tasks.md#task-1
 */
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
		/** @spec openspec/changes/retrofit-2026-05-26-menu-page-management/tasks.md#task-4 */
		pageItem() {
			return objectStore.getActiveObject('page')
		},
		/** @spec openspec/changes/retrofit-2026-05-26-menu-page-management/tasks.md#task-4 */
		contentItem() {
			return objectStore.getActiveObject('pageContent')
		},
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-menu-page-management/tasks.md#task-4 */
		closeDialog() {
			navigationStore.setDialog(false)
			objectStore.setState('page', { success: null, error: null })
			objectStore.clearActiveObject('pageContent')
		},
		/** @spec openspec/changes/retrofit-2026-05-26-menu-page-management/tasks.md#task-4 */
		async handleDelete() {
			this.loading = true
			this.success = null
			this.error = null

			try {
				const clone = structuredClone(this.pageItem)
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
