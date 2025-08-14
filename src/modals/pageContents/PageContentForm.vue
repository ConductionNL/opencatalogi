<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { getTheme } from '../../services/getTheme.js'
import { EventBus } from '../../eventBus.js'
import { getNextcloudGroups } from '../../services/nextcloudGroups.js'
</script>

<template>
	<NcDialog
		:name="isEdit ? `Content edit of ${_.upperFirst(contentsItem.type)}` : `Add Content to ${pageItem.name}`"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<div class="dialog__content">
			<div v-if="objectStore.getState('page').success !== null || objectStore.getState('page').error">
				<NcNoteCard v-if="objectStore.getState('page').success" type="success">
					<p>Content successfully added</p>
				</NcNoteCard>
				<NcNoteCard v-if="!objectStore.getState('page').success" type="error">
					<p>Something went wrong while adding content</p>
				</NcNoteCard>
				<NcNoteCard v-if="objectStore.getState('page').error" type="error">
					<p>{{ objectStore.getState('page').error }}</p>
				</NcNoteCard>
			</div>

			<div v-if="objectStore.getState('page').success === null" class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<!-- Configuration Tab -->
					<BTab title="Configuration" active>
						<div class="form-group">
							<p>
								The order in which you add contents makes a difference, so pay attention to the order.
							</p>

							<NcSelect
								v-if="!isEdit"
								v-bind="typeOptions"
								v-model="contentsItem.type"
								input-label="Content type"
								required />

							<!-- Order -->
							<NcTextField
								:disabled="objectStore.isLoading('page')"
								label="Order"
								type="number"
								min="0"
								:value.sync="contentsItem.order"
								required />

							<!-- RichText -->
							<div v-if="contentsItem.type === 'RichText'">
								<label>Rich Text Content</label>
								<v-md-editor
									v-model="contentsItem.richTextData"
									:disabled="objectStore.isLoading('page')"
									height="300px"
									mode="edit" />
							</div>

							<!-- Faq -->
							<div v-if="contentsItem.type === 'Faq'">
								<VueDraggable v-model="contentsItem.faqData" easing="ease-in-out" draggable="div:not(:last-child)">
									<div v-for="item in contentsItem.faqData" :key="item.id" class="draggable-item-container">
										<div :class="`draggable-form-item ${getTheme()}`">
											<Drag class="drag-handle" :size="40" />
											<NcTextField label="Vraag" :value.sync="item.question" />
											<NcTextField label="Antwoord" :value.sync="item.answer" />
										</div>
									</div>
								</VueDraggable>
							</div>
						</div>
					</BTab>

					<!-- Security Tab -->
					<BTab title="Security">
						<div class="form-group">
							<!-- Security Section -->
							<div class="groups-section">
								<label class="groups-label">Groups Access</label>
								<NcNoteCard type="info">
									<p>When you add groups to a content block, it will only appear if the user belongs to one of the selected groups. If no groups are selected, the content will be visible to all users.</p>
								</NcNoteCard>
								<NcSelect
									v-model="contentsItem.groups"
									:options="groupsOptions.options"
									:disabled="objectStore.isLoading('page') || groupsOptions.loading"
									input-label="Select Groups"
									multiple />
								<p v-if="groupsOptions.loading" class="groups-loading">
									Loading groups...
								</p>
							</div>

							<!-- Hide After Login -->
							<div class="hide-after-login">
								<NcNoteCard type="info">
									<p>When checked, this content block will be hidden after a user is logged in. This is useful for content that should only be visible to guests, such as login forms or registration information.</p>
								</NcNoteCard>
								<NcCheckboxRadioSwitch
									:checked.sync="contentsItem.hideAfterInlog"
									:disabled="objectStore.isLoading('page')">
									Hide after login
								</NcCheckboxRadioSwitch>
							</div>
						</div>
					</BTab>
				</BTabs>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				{{ isEdit ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="objectStore.getState('page').success === null"
				:disabled="!contentsItem.type || objectStore.isLoading('page')"
				type="primary"
				@click="addPageContent">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.isLoading('page')" :size="20" />
					<Plus v-if="!objectStore.isLoading('page')" :size="20" />
				</template>
				{{ isEdit ? 'Edit' : 'Add' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import { VueDraggable } from 'vue-draggable-plus'
import _ from 'lodash'
import { Editor as vMdEditor } from '@toast-ui/vue-editor'

import Plus from 'vue-material-design-icons/Plus.vue'
import Drag from 'vue-material-design-icons/Drag.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

import { Page } from '../../entities/index.js'

export default {
	name: 'PageContentForm',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		BTabs,
		BTab,
		VueDraggable,
		NcTextField,
		NcCheckboxRadioSwitch,
		vMdEditor,
		// Icons
		Plus,
		Drag,
	},
	data() {
		return {
			isEdit: !!objectStore.getActiveObject('pageContent')?.id,
			contentsItem: {
				type: '',
				order: 0,
				richTextData: '',
				id: Math.random().toString(36).substring(2, 12),
				faqData: [
					{
						id: Math.random().toString(36).substring(2, 12),
						question: '',
						answer: '',
					},
				],
				groups: [],
				hideAfterInlog: false,
			},
			typeOptions: {
				options: ['RichText', 'Faq'],
			},
			success: null,
			error: false,
			errorCode: '',
			hasUpdated: false,
			groupsOptions: {
				options: [],
				loading: false,
			},
		}
	},
	computed: {
		pageItem() {
			return objectStore.getActiveObject('page')
		},
	},
	watch: {
		'contentsItem.faqData': {
			handler(newVal) {
				const currentFaqLength = newVal.length

				// check if last item is full, then add a new one to the list
				if (newVal[currentFaqLength - 1].question !== '' && newVal[currentFaqLength - 1].answer !== '') {
					newVal.push({
						id: Math.random().toString(36).substring(2, 12),
						question: '',
						answer: '',
					})
				}

				// Remove any empty FAQ items except the last one
				if (currentFaqLength > 1) {
					for (let i = currentFaqLength - 2; i >= 0; i--) {
						if (newVal[i].question === '' && newVal[i].answer === '') {
							newVal.splice(i, 1)
						}
					}
				}
			},
			deep: true,
		},
	},
	mounted() {
		// Fetch groups for the dropdown
		this.fetchGroups()

		if (this.isEdit) {
			const contentItem = this.pageItem.contents.find((content) => content.id === objectStore.getActiveObject('pageContent').id)

			// put in all data that does not require special handeling
			this.contentsItem = {
				...this.contentsItem,
				type: contentItem.type,
				order: contentItem.order || 0,
				richTextData: contentItem.data.content || '',
				id: contentItem.id,
				groups: contentItem.groups || [],
				hideAfterInlog: contentItem.hideAfterInlog || false,
			}

			// if faqs are present, prepend them to the contentsItem
			if (contentItem.data.faqs && contentItem.data.faqs.length > 0) {
				this.contentsItem.faqData = contentItem.data.faqs.map((faq) => ({
					id: Math.random().toString(36).substring(2, 12),
					question: faq.question,
					answer: faq.answer,
				})).concat(this.contentsItem.faqData)
			}
		}
	},
	methods: {
		/**
		 * Handle dialog close event
		 * @param {boolean} isOpen - Whether the dialog is open
		 * @return {void}
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('pageContent')
			objectStore.setState('page', { success: null, error: null })
		},
		addPageContent() {
			objectStore.setState('page', { success: null, error: null, loading: true })

			const pageItemClone = _.cloneDeep(this.pageItem)

			// Create the content item
			// a different data format is needed for the type of content
			let contentItem
			if (this.contentsItem.type === 'RichText') {
				contentItem = {
					type: this.contentsItem.type,
					order: this.contentsItem.order || 0,
					id: this.contentsItem.id || Math.random().toString(36).substring(2, 12),
					data: {
						content: this.contentsItem.richTextData,
						groups: this.contentsItem.groups,
						hideAfterInlog: this.contentsItem.hideAfterInlog,
					},
				}
			} else if (this.contentsItem.type === 'Faq') {
				contentItem = {
					type: this.contentsItem.type,
					order: this.contentsItem.order || 0,
					id: this.contentsItem.id || Math.random().toString(36).substring(2, 12),
					data: {
						// remove the last item since it's a placeholder and is always empty no matter what
						faqs: this.contentsItem.faqData.slice(0, -1).map((faq) => ({
							question: faq.question,
							answer: faq.answer,
						})),
						groups: this.contentsItem.groups,
						hideAfterInlog: this.contentsItem.hideAfterInlog,
					},
				}
			}

			if (!Array.isArray(pageItemClone.contents)) {
				pageItemClone.contents = []
			}

			// Check if it's an edit modal by checking if contentId exists
			if (objectStore.getActiveObject('pageContent')?.id) {
				const index = pageItemClone.contents.findIndex(content => content.id === objectStore.getActiveObject('pageContent').id)
				if (index !== -1) {
					pageItemClone.contents[index] = contentItem
				}
			} else {
				pageItemClone.contents.push(contentItem)
			}

			const newPageItem = new Page(pageItemClone)

			objectStore.updateObject('page', this.pageItem.id, newPageItem)
				.then(() => {
					objectStore.setState('page', { success: true })
					// Wait for the user to read the feedback then return to parent dialog
					setTimeout(() => {
						navigationStore.setModal('viewPage')
					}, 2000)

					EventBus.$emit('edit-page-content-success')

					this.hasUpdated = false
				})
				.catch((err) => {
					objectStore.setState('page', { error: err })
					this.hasUpdated = false
				})
				.finally(() => {
					objectStore.setState('page', { loading: false })
				})
		},
		fetchGroups() {
			this.groupsOptions.loading = true
			getNextcloudGroups()
				.then((groups) => {
					this.groupsOptions.options = groups
				})
				.catch((err) => {
					console.error('Error fetching groups:', err)
				})
				.finally(() => {
					this.groupsOptions.loading = false
				})
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
</style>

<style scoped>
.tabContainer {
	margin-top: var(--OC-margin-20);
}

.draggable-form-item {
    display: flex;
    align-items: center;
    gap: 3px;

    background-color: rgba(255, 255, 255, 0.05);
    padding: 4px;
    border-radius: 12px;

    margin-block: 8px;
}
.draggable-form-item.light {
    background-color: rgba(0, 0, 0, 0.05);
}
.draggable-form-item :deep(.v-select) {
    min-width: 150px;
}
.draggable-form-item :deep(.input-field__label) {
    margin-block-start: 0 !important;
}
.draggable-form-item .input-field {
    margin-block-start: 0 !important;
}

.draggable-item-container:last-child .drag-handle {
    cursor: not-allowed;
}

.groups-section {
    margin-block-start: var(--OC-margin-20);
    margin-block-end: var(--OC-margin-20);
}

.groups-label {
    display: block;
    margin-block-end: var(--OC-margin-10);
    font-weight: bold;
}

.groups-loading {
	margin-block-start: var(--OC-margin-10);
	font-size: 12px;
	color: var(--OC-text-color-light);
}

.hide-after-login {
	margin-block-start: var(--OC-margin-20);
}

/* Markdown Editor Styles */
:deep(.v-md-editor) {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

:deep(.v-md-editor__toolbar) {
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}

:deep(.v-md-editor__editor) {
	background-color: var(--color-main-background);
}
</style>
