/**
 * PageModal.vue
 * Component for adding and editing pages
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
import { getNextcloudGroups } from '../../services/nextcloudGroups.js'
</script>

<template>
	<NcModal
		ref="modalRef"
		:name="isEdit ? 'Edit page' : 'Add page'"
		:label-id="isEdit ? 'editPageModal' : 'addPageModal'"
		@close="closeModal">
		<div class="modal__content">
			<div v-if="objectStore.getState('page').success !== null || objectStore.getState('page').error">
				<NcNoteCard v-if="objectStore.getState('page').success" type="success">
					<p>{{ isEdit ? 'Page successfully edited' : 'Page successfully added' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="!objectStore.getState('page').success" type="error">
					<p>{{ isEdit ? 'Something went wrong while editing the page' : 'Something went wrong while adding the page' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="objectStore.getState('page').error" type="error">
					<p>{{ objectStore.getState('page').error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="objectStore.getState('page').success === null" class="formContainer">
				<NcTextField
					:disabled="objectStore.isLoading('page')"
					label="Title"
					:value.sync="page.title"
					:error="!!inputValidation.fieldErrors?.['title']"
					:helper-text="inputValidation.fieldErrors?.['title']?.[0]" />
				<NcTextField
					:disabled="objectStore.isLoading('page')"
					label="Slug"
					:value.sync="page.slug"
					:error="!!inputValidation.fieldErrors?.['slug']"
					:helper-text="inputValidation.fieldErrors?.['slug']?.[0]" />

				<!-- Groups Access Control -->
				<div class="groups-section">
					<label class="groups-label">Groups Access</label>
					<NcNoteCard type="info">
						<p>When you add groups to a page, it will only be accessible if the user belongs to one of the selected groups. If no groups are selected, the page will be visible to all users.</p>
					</NcNoteCard>
					<select
						v-model="page.groups"
						:disabled="objectStore.isLoading('page') || groupsOptions.loading"
						multiple
						class="groups-select">
						<option
							v-for="group in groupsOptions.options"
							:key="group.value"
							:value="group.value">
							{{ group.label }}
						</option>
					</select>
					<p v-if="groupsOptions.loading" class="groups-loading">
						Loading groups...
					</p>
				</div>

				<!-- Groups Refresh Button -->
				<div class="groups-refresh">
					<NcButton
						:disabled="groupsOptions.loading"
						type="secondary"
						size="small"
						@click="fetchGroups">
						<template #icon>
							<Refresh v-if="!groupsOptions.loading" :size="16" />
							<NcLoadingIcon v-else :size="16" />
						</template>
						{{ groupsOptions.loading ? 'Loading...' : 'Refresh Groups' }}
					</NcButton>
				</div>

				<!-- Hide After Login -->
				<div class="hide-after-login">
					<NcCheckboxRadioSwitch
						:checked.sync="page.hideAfterInlog"
						:disabled="objectStore.isLoading('page')">
						Verberg na inloggen
					</NcCheckboxRadioSwitch>
					<NcNoteCard type="info">
						<p>When checked, this page will be hidden after a user is logged in. This is useful for pages that should only be visible to guests, such as login pages or registration forms.</p>
					</NcNoteCard>
				</div>
			</div>
			<div class="modalActions">
				<NcButton class="modalCloseButton" @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ isEdit ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton v-if="objectStore.getState('page').success === null"
					v-tooltip="inputValidation.errorMessages?.[0]"
					:disabled="!inputValidation.success || objectStore.isLoading('page')"
					type="primary"
					@click="savePage">
					<template #icon>
						<NcLoadingIcon v-if="objectStore.isLoading('page')" :size="20" />
						<Plus v-if="!objectStore.isLoading('page')" :size="20" />
					</template>
					{{ isEdit ? 'Save' : 'Add' }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextField,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

import { Page } from '../../entities/index.js'

export default {
	name: 'PageModal',
	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		// Icons
		Plus,
		Refresh,
		Cancel,
	},
	data() {
		return {
			page: {
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
		isEdit() {
			return !!objectStore.getActiveObject('page')
		},
		inputValidation() {
			const pageItem = new Page({
				...this.page,
			})

			const result = pageItem.validate()

			return {
				success: result.success,
				errorMessages: result?.error?.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`) || [],
				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},
	mounted() {
		// Fetch groups for the dropdown
		this.fetchGroups()
	},
	updated() {
		if (navigationStore.modal === 'page' && !this.hasUpdated) {
			if (this.isEdit) {
				const activePage = objectStore.getActiveObject('page')
				this.page = {
					...activePage,
					groups: activePage.groups || [],
					hideAfterInlog: activePage.hideAfterInlog || false,
				}
			}
			this.hasUpdated = true
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.hasUpdated = false
			this.page = {
				title: '',
				slug: '',
				groups: [],
				hideAfterInlog: false,
			}
			// Reset the object store state
			objectStore.setState('page', { success: null, error: null })
		},
		savePage() {
			const pageItem = new Page({
				...this.page,
			})

			if (this.isEdit) {
				objectStore.updateObject('page', pageItem.id, pageItem)
					.then(() => {
						// Wait for the user to read the feedback then close the model
						const self = this
						setTimeout(function() {
							self.closeModal()
						}, 2000)
					})
			} else {
				objectStore.createObject('page', pageItem)
					.then(() => {
						// Wait for the user to read the feedback then close the model
						const self = this
						setTimeout(function() {
							self.closeModal()
						}, 2000)
					})
			}
		},
		fetchGroups() {
			this.groupsOptions.loading = true
			getNextcloudGroups()
				.then((groups) => {
					this.groupsOptions.options = groups
				})
				.catch(error => {
					console.error('Error fetching groups:', error)
				})
				.finally(() => {
					this.groupsOptions.loading = false
				})
		},
	},
}
</script>

<style>
.formContainer > * {
  margin-block-end: 10px;
}

.success {
  color: green;
}

.pageSpacing {
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.groups-section {
	margin-top: 10px;
}

.groups-label {
	font-weight: bold;
	margin-bottom: 5px;
}

.groups-select {
	width: 100%;
	padding: 8px;
	border: 1px solid #ccc;
	border-radius: 4px;
	background-color: #f9f9f9;
}

.groups-loading {
	color: #666;
	font-style: italic;
	margin-top: 5px;
}

.groups-refresh {
	margin-top: 10px;
}

.hide-after-login {
	margin-top: 10px;
}
</style>
