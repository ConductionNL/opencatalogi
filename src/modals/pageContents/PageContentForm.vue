<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { getTheme } from '../../services/getTheme.js'
import { EventBus } from '../../eventBus.js'
import { getNextcloudGroups } from '../../services/nextcloudGroups.js'
</script>

<template>
	<NcDialog
		:name="isEdit ? `Content edit of ${_.upperFirst(contentsItem.type)}` : `Add Content to ${pageItem.title}`"
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

							<!-- text (plain text) -->
							<div v-if="contentsItem.type === 'text'" class="form-group">
								<label for="text-content">Text Content</label>
								<textarea
									id="text-content"
									v-model="contentsItem.textData"
									class="text-content-textarea"
									:disabled="objectStore.isLoading('page')"
									rows="10"
									placeholder="Enter your text content here..." />
							</div>

							<!-- RichText -->
							<div v-if="contentsItem.type === 'RichText'" class="editor-container">
								<label>Rich Text Content</label>
								<v-md-editor
									:initial-value="contentsItem.richTextData"
									:options="editorOptions"
									initial-edit-type="wysiwyg"
									preview-style="tab"
									height="300px"
									@load="(editor) => richTextEditor = editor" />
							</div>

							<!-- Image -->
							<div v-if="contentsItem.type === 'Image'" class="form-group">
								<NcTextField
									:disabled="objectStore.isLoading('page')"
									label="Image URL"
									:value.sync="contentsItem.imageUrl"
									placeholder="https://example.com/image.jpg" />
								<NcTextField
									:disabled="objectStore.isLoading('page')"
									label="Srcset (optional, responsive images)"
									:value.sync="contentsItem.imageSrcset"
									placeholder="image-480w.jpg 480w, image-800w.jpg 800w" />
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
							<div class="hide-after-login">
								<NcNoteCard type="info">
									<p>When checked, this content block will be hidden after a user is logged in. This is useful for content that should only be visible to guests, such as login forms or registration information.</p>
								</NcNoteCard>
								<NcCheckboxRadioSwitch
									:checked.sync="contentsItem.hideAfterLogin"
									:disabled="contentsItem.hideBeforeLogin || objectStore.isLoading('page')">
									Hide after login
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									:checked.sync="contentsItem.hideBeforeLogin"
									:disabled="contentsItem.hideAfterLogin || objectStore.isLoading('page')">
									Hide before login
								</NcCheckboxRadioSwitch>
								<p v-if="contentsItem.hideAfterLogin && contentsItem.hideBeforeLogin" class="field-error">
									'Hide before login' and 'Hide after login' cannot both be selected.
								</p>
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
					<ContentSave v-else-if="isEdit" :size="20" />
					<Plus v-else :size="20" />
				</template>
				{{ isEdit ? 'Save' : 'Add' }}
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
import '@toast-ui/editor/dist/toastui-editor.css'

import Plus from 'vue-material-design-icons/Plus.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Drag from 'vue-material-design-icons/Drag.vue'

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
		ContentSave,
		Drag,
	},
	data() {
		return {
			isEdit: !!objectStore.getActiveObject('pageContent')?.id,
			contentsItem: {
				type: '',
				order: 0,
				richTextData: '',
				textData: '',
				imageUrl: '',
				imageSrcset: '',
				id: Math.random().toString(36).substring(2, 12),
				faqData: [
					{
						id: Math.random().toString(36).substring(2, 12),
						question: '',
						answer: '',
					},
				],
				groups: [],
				hideAfterLogin: false,
				hideBeforeLogin: false,
			},
			typeOptions: {
				options: ['text', 'RichText', 'Image', 'Faq'],
			},
			success: null,
			error: false,
			errorCode: '',
			hasUpdated: false,
			groupsOptions: {
				options: [],
				loading: false,
			},
			textEditor: null,
			richTextEditor: null,
			editorOptions: {
				minHeight: '200px',
				language: 'en-US',
				hideModeSwitch: true,
				toolbarItems: [
					['heading', 'bold', 'italic', 'strike'],
					['hr', 'quote'],
					['ul', 'ol', 'task', 'indent', 'outdent'],
					['table', 'image', 'link'],
					['code', 'codeblock'],
				],
				initialEditType: 'wysiwyg',
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
		// Fetch groups for the dropdown.
		this.fetchGroups()

		if (this.isEdit) {
			const contentItem = this.pageItem.contents.find((content) => content.id === objectStore.getActiveObject('pageContent').id)

			// Put in all data that does not require special handling.
			this.contentsItem = {
				...this.contentsItem,
				type: contentItem.type,
				order: contentItem.order || 0,
				id: contentItem.id,
				groups: contentItem.groups || [],
				hideAfterLogin: contentItem.hideAfterLogin || false,
				hideBeforeLogin: contentItem.hideBeforeLogin || false,
			}

			// Handle different content formats.
			// Legacy "text" format: data.html and data.text
			if (contentItem.type === 'text') {
				this.contentsItem.textData = contentItem.data.html || contentItem.data.text || ''
			} else if (contentItem.type === 'RichText') {
				this.contentsItem.richTextData = contentItem.data.content || ''
			} else if (contentItem.type === 'Image') {
				this.contentsItem.imageUrl = contentItem.data.url || ''
				this.contentsItem.imageSrcset = contentItem.data.srcset || ''
			}

			// If faqs are present, prepend them to the contentsItem.
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

			// Read content from editor instances (Toast UI editor uses initial-value, not v-model).
			const textContent = this.textEditor ? this.textEditor.getHTML() : this.contentsItem.textData
			const richTextContent = this.richTextEditor ? this.richTextEditor.getHTML() : this.contentsItem.richTextData

			// Create the content item.
			// A different data format is needed for the type of content.
			let contentItem
			if (this.contentsItem.type === 'text') {
				contentItem = {
					type: this.contentsItem.type,
					order: this.contentsItem.order || 0,
					id: this.contentsItem.id || Math.random().toString(36).substring(2, 12),
					data: {
						html: textContent,
						text: textContent.replace(/<[^>]*>/g, ''), // Strip HTML tags for text field.
					},
					groups: this.normalizeGroups(this.contentsItem.groups),
					hideAfterLogin: this.contentsItem.hideAfterLogin,
					hideBeforeLogin: this.contentsItem.hideBeforeLogin,
				}
			} else if (this.contentsItem.type === 'RichText') {
				contentItem = {
					type: this.contentsItem.type,
					order: this.contentsItem.order || 0,
					id: this.contentsItem.id || Math.random().toString(36).substring(2, 12),
					data: {
						content: richTextContent,
					},
					groups: this.normalizeGroups(this.contentsItem.groups),
					hideAfterLogin: this.contentsItem.hideAfterLogin,
					hideBeforeLogin: this.contentsItem.hideBeforeLogin,
				}
			} else if (this.contentsItem.type === 'Image') {
				contentItem = {
					type: this.contentsItem.type,
					order: this.contentsItem.order || 0,
					id: this.contentsItem.id || Math.random().toString(36).substring(2, 12),
					data: {
						url: this.contentsItem.imageUrl,
						srcset: this.contentsItem.imageSrcset || undefined,
					},
					groups: this.normalizeGroups(this.contentsItem.groups),
					hideAfterLogin: this.contentsItem.hideAfterLogin,
					hideBeforeLogin: this.contentsItem.hideBeforeLogin,
				}
			} else if (this.contentsItem.type === 'Faq') {
				contentItem = {
					type: this.contentsItem.type,
					order: this.contentsItem.order || 0,
					id: this.contentsItem.id || Math.random().toString(36).substring(2, 12),
					data: {
						// Remove the last item since it's a placeholder and is always empty no matter what.
						faqs: this.contentsItem.faqData.slice(0, -1).map((faq) => ({
							question: faq.question,
							answer: faq.answer,
						})),
					},
					groups: this.normalizeGroups(this.contentsItem.groups),
					hideAfterLogin: this.contentsItem.hideAfterLogin,
					hideBeforeLogin: this.contentsItem.hideBeforeLogin,
				}
			}

			if (!Array.isArray(pageItemClone.contents)) {
				pageItemClone.contents = []
			}

			// Check if it's an edit modal by checking if contentId exists.
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
					// Wait for the user to read the feedback then return to parent dialog.
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
		/**
		 * Normalize groups array to ensure consistent format
		 * @param {Array} selected - Selected groups from NcSelect
		 * @return {Array} Normalized groups array
		 */
		normalizeGroups(selected) {
			if (!Array.isArray(selected)) return []
			return selected.map(item => {
				if (typeof item === 'string') return item
				if (item && typeof item === 'object') return item.value ?? String(item.label ?? '')
				return ''
			}).filter(Boolean)
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

.text-content-textarea {
	width: 100%;
	min-height: 200px;
	padding: 12px;
	font-family: var(--font-face);
	font-size: var(--default-font-size);
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	resize: vertical;
}

.text-content-textarea:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

/* Toast UI Editor Styles */
.editor-container {
	width: 100%;
}

.editor-container :deep(.toastui-editor-defaultUI) {
	font-family: var(--font-face) !important;
	font-size: var(--default-font-size) !important;
	background-color: var(--color-main-background) !important;
	width: 100% !important;
	border: 1px solid var(--color-border) !important;
	border-radius: var(--border-radius) !important;
}

.editor-container :deep(.toastui-editor-toolbar) {
	background-color: var(--color-background-hover) !important;
	border-bottom: 1px solid var(--color-border-dark) !important;
	padding: 8px !important;
}

.editor-container :deep(.toastui-editor-toolbar-icons button) {
	color: var(--color-main-text) !important;
	background-color: transparent !important;
	border: none !important;
	border-radius: var(--border-radius) !important;
	padding: 6px !important;
	margin: 2px !important;
}

.editor-container :deep(.toastui-editor-toolbar-icons button:hover) {
	background-color: var(--color-background-dark) !important;
}

.editor-container :deep(.toastui-editor-toolbar-icons button.active) {
	background-color: var(--color-primary-element) !important;
	color: var(--color-primary-element-text) !important;
}

.editor-container :deep(.toastui-editor-mode-switch) {
	display: none !important;
}

.editor-container :deep(.toastui-editor-contents) {
	color: var(--color-main-text) !important;
	font-family: var(--font-face) !important;
}

.editor-container :deep(.toastui-editor.ww-mode) {
	width: 100% !important;
	height: 100% !important;
}

.editor-container :deep(.ProseMirror) {
	background-color: var(--color-main-background) !important;
	color: var(--color-main-text) !important;
	font-family: var(--font-face) !important;
	font-size: var(--default-font-size) !important;
	padding: 12px !important;
	min-height: 200px !important;
	width: 100% !important;
	height: 100% !important;
}
</style>
