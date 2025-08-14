/**
 * ViewPageModal.vue
 * Modal component for viewing page details and content
 * @category Modals
 * @package opencatalogi
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/opencatalogi
 */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'viewPage'"
		:name="getModalTitle()"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<div class="dialog__content">
			<div class="pageDetails">
				<!-- Content Items Tab -->
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab :title="`Content Items (${page.contents?.length || 0})`" active>
							<div v-if="page.contents?.length">
								<div class="contentItemsList">
									<div v-for="(content, index) in page.contents"
										:key="content.id || index"
										class="contentItem">
										<div class="contentItemHeader">
											<strong>{{ content.title || content.name || `Content ${index + 1}` }}</strong>
											<span v-if="content.type" class="contentItemType">
												{{ content.type }}
											</span>
											<div class="contentItemActions">
												<NcButton type="secondary" @click="editContent(content)">
													<template #icon>
														<Pencil :size="18" />
													</template>
													{{ t('opencatalogi', 'Edit') }}
												</NcButton>
												<NcButton type="error" @click="deleteContent(content)">
													<template #icon>
														<Delete :size="18" />
													</template>
													{{ t('opencatalogi', 'Delete') }}
												</NcButton>
											</div>
										</div>
										<div v-if="content.description" class="contentItemDescription">
											{{ content.description }}
										</div>
										<div v-if="content.content" class="contentItemContent">
											<strong>Content:</strong>
											<div class="contentPreview">
												{{ content.content.substring(0, 200) }}{{ content.content.length > 200 ? '...' : '' }}
											</div>
										</div>
										<div v-if="content.order !== undefined" class="contentItemOrder">
											<strong>Order:</strong> {{ content.order }}
										</div>
									</div>
								</div>
							</div>

							<div v-else>
								<p class="emptyContentItems">
									{{ t('opencatalogi', 'No content items configured') }}
								</p>
							</div>
						</BTab>

						<!-- Configuration Tab -->
						<BTab title="Configuration">
							<div>
								<!-- Success/Error Messages -->
								<div v-if="pageState.success !== null || pageState.error" class="messageContainer">
									<NcNoteCard v-if="pageState.success" type="success">
										<p>{{ isEdit ? 'Page successfully edited' : 'Page successfully added' }}</p>
									</NcNoteCard>
									<NcNoteCard v-if="!pageState.success" type="error">
										<p>{{ isEdit ? 'Something went wrong while editing the page' : 'Something went wrong while adding the page' }}</p>
									</NcNoteCard>
									<NcNoteCard v-if="pageState.error" type="error">
										<p>{{ pageState.error }}</p>
									</NcNoteCard>
								</div>

								<!-- Edit Form -->
								<div v-if="pageState.success === null" class="formContainer">
									<NcTextField
										:disabled="objectStore.isLoading('page')"
										label="Title"
										:value.sync="editForm.title"
										:error="!!inputValidation.fieldErrors?.['title']"
										:helper-text="inputValidation.fieldErrors?.['title']?.[0]" />

									<NcTextField
										:disabled="objectStore.isLoading('page')"
										label="Slug"
										:value.sync="editForm.slug"
										:error="!!inputValidation.fieldErrors?.['slug']"
										:helper-text="inputValidation.fieldErrors?.['slug']?.[0]" />
								</div>
							</div>
						</BTab>

						<!-- Security Tab -->
						<BTab title="Security">
							<div>
								<!-- Groups Access Control -->
								<div class="groups-section">
									<label class="groups-label">Groups Access</label>
									<NcNoteCard type="info">
										<p>When you add groups to a page, it will only be accessible if the user belongs to one of the selected groups. If no groups are selected, the page will be visible to all users.</p>
									</NcNoteCard>
									<NcSelect
										v-model="editForm.groups"
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
									<NcCheckboxRadioSwitch
										:checked.sync="editForm.hideAfterInlog"
										:disabled="objectStore.isLoading('page')">
										Verberg na inloggen
									</NcCheckboxRadioSwitch>
									<NcNoteCard type="info">
										<p>When checked, this page will be hidden after a user is logged in. This is useful for pages that should only be visible to guests, such as login pages or registration forms.</p>
									</NcNoteCard>
								</div>
							</div>
						</BTab>
					</BTabs>
				</div>

				<div v-if="page.metadata">
					<div class="metadataContainer">
						<pre>{{ JSON.stringify(page.metadata, null, 2) }}</pre>
					</div>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton type="secondary" @click="openAddContentModal">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('opencatalogi', 'Add Content') }}
			</NcButton>
			<NcButton @click="closeModal">
				{{ t('opencatalogi', 'Close') }}
			</NcButton>
			<NcButton type="error" @click="deletePage">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!inputValidation.success || objectStore.isLoading('page')"
				@click="savePage">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.isLoading('page')" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ t('opencatalogi', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField, NcCheckboxRadioSwitch, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { getNextcloudGroups } from '../../services/nextcloudGroups.js'
import { Page } from '../../entities/index.js'

export default {
	name: 'ViewPageModal',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcLoadingIcon,
		BTabs,
		BTab,
		Pencil,
		Plus,
		Delete,
		ContentSave,
	},
	data() {
		return {
			editForm: {
				title: '',
				slug: '',
				groups: [],
				hideAfterInlog: false,
			},
			hasUpdated: false,
			groupsOptions: {
				options: [],
				loading: false,
			},
		}
	},
	computed: {
		/**
		 * Get the currently active page from the store
		 * @return {object|null} The active page object
		 */
		page() {
			return objectStore.getActiveObject('page')
		},
		/**
		 * Check if we're in edit mode
		 * @return {boolean} True if editing an existing page
		 */
		isEdit() {
			return !!this.page
		},
		/**
		 * Get the page state from the store
		 * @return {object} The page state object
		 */
		pageState() {
			return objectStore.getState('page')
		},
		/**
		 * Validate the input form
		 * @return {object} Validation result
		 */
		inputValidation() {
			const pageItem = new Page({
				...this.page,
				...this.editForm,
			})

			const result = pageItem.validate()

			return {
				success: result.success,
				errorMessages: result?.error?.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`) || [],
				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},
	watch: {
		/**
		 * Watch for changes in the page data and update editForm accordingly
		 * @param {object} newPage - The new page data
		 */
		page: {
			handler(newPage) {
				if (newPage && this.isEdit) {
					// Initialize editForm with existing page data
					this.editForm = {
						title: newPage.title || '',
						slug: newPage.slug || '',
						groups: newPage.groups || [],
						hideAfterInlog: newPage.hideAfterInlog || false,
					}
				}
			},
			immediate: true,
		},
	},
	mounted() {
		// Fetch groups for the dropdown
		this.fetchGroups()
	},
	methods: {
		/**
		 * Get the modal title
		 * @return {string} The modal title
		 */
		getModalTitle() {
			return this.page?.title || 'Page'
		},
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
		/**
		 * Close the modal and clear the active object
		 * @return {void}
		 */
		closeModal() {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('page')
		},
		/**
		 * Open the edit modal for the current page
		 * @return {void}
		 */
		openEditModal() {
			navigationStore.setModal('viewPage')
		},
		/**
		 * Open the add content modal
		 * @return {void}
		 */
		openAddContentModal() {
			navigationStore.setModal('pageContentForm')
		},
		/**
		 * Open edit modal for a specific content item
		 * @param {object} content
		 */
		editContent(content) {
			objectStore.setActiveObject('pageContent', content)
			navigationStore.setModal('pageContentForm')
		},
		/**
		 * Open delete confirmation dialog for a specific content item
		 * @param {object} content
		 */
		deleteContent(content) {
			objectStore.setActiveObject('pageContent', content)
			navigationStore.setDialog('deletePageContent')
		},
		/**
		 * Fetch groups from Nextcloud
		 * @return {void}
		 */
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
		 * Save the page configuration
		 * @return {void}
		 */
		savePage() {
			const pageItem = new Page({
				...this.page,
				...this.editForm,
			})

			if (this.isEdit) {
				objectStore.updateObject('page', pageItem.id, pageItem)
					.then(() => {
						// Wait for the user to read the feedback then close the modal
						setTimeout(() => {
							this.closeModal()
						}, 2000)
					})
			} else {
				objectStore.createObject('page', pageItem)
					.then(() => {
						// Wait for the user to read the feedback then close the modal
						setTimeout(() => {
							this.closeModal()
						}, 2000)
					})
			}
		},
		/**
		 * Delete the current page
		 * @return {void}
		 */
		deletePage() {
			if (this.page && this.page.id) {
				objectStore.deleteObject('page', this.page.id)
					.then(() => {
						this.closeModal()
					})
					.catch((error) => {
						console.error('Error deleting page:', error)
					})
			}
		},
	},
}
</script>

<style scoped>
.dialog__content {
	text-align: left;
	max-width: 80vw;
	max-height: 80vh;
	overflow-y: auto;
}

.pageDetails {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-20);
	margin-top: var(--OC-margin-20);
}

.detailSection {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: var(--OC-margin-20);
}

.detailSection h3 {
	margin: 0 0 var(--OC-margin-15) 0;
	color: var(--color-primary);
	font-weight: bold;
}

.detailGrid {
	display: grid;
	grid-template-columns: 1fr;
	gap: var(--OC-margin-10);
}

.detailItem {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-5);
}

.detailItem strong {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.detailItem span {
	color: var(--color-main-text);
}

.contentItemsList {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-15);
}

.contentItem {
	padding: var(--OC-margin-15);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-primary);
}

.contentItemHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: var(--OC-margin-5);
}

.contentItemHeader strong {
	color: var(--color-main-text);
	font-size: 1em;
}

.contentItemType {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	background-color: var(--color-background-dark);
	padding: var(--OC-margin-5);
	border-radius: var(--border-radius);
}

.contentItemActions {
	display: flex;
	gap: var(--OC-margin-10);
}

.contentItemDescription {
	margin-bottom: var(--OC-margin-5);
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.contentItemContent {
	margin-bottom: var(--OC-margin-5);
	font-size: 0.85em;
}

.contentItemContent strong {
	color: var(--color-text-maxcontrast);
}

.contentPreview {
	margin-top: var(--OC-margin-5);
	padding: var(--OC-margin-10);
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-family: monospace;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.contentItemOrder {
	font-size: 0.85em;
}

.contentItemOrder strong {
	color: var(--color-text-maxcontrast);
}

.emptyContentItems {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: var(--OC-margin-20);
}

.emptyConfiguration {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: var(--OC-margin-20);
}

.emptySecurity {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: var(--OC-margin-20);
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

.tabContainer {
	margin-top: var(--OC-margin-20);
}

.metadataContainer {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: var(--OC-margin-15);
	overflow-x: auto;
}

.metadataContainer pre {
	margin: 0;
	font-family: 'Courier New', monospace;
	font-size: 0.85em;
	color: var(--color-main-text);
	white-space: pre-wrap;
	word-wrap: break-word;
}

.emptyState {
	text-align: center;
	padding: var(--OC-margin-50);
	color: var(--color-text-lighter);
}

@media (min-width: 768px) {
	.detailGrid {
		grid-template-columns: repeat(2, 1fr);
	}
}
</style>
