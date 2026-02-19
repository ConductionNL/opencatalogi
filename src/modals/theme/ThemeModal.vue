<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'theme'"
		ref="modalRef"
		:name="isEdit ? 'Edit theme' : 'Add theme'"
		:label-id="isEdit ? 'editThemeModal' : 'addThemeModal'"
		@close="closeModal">
		<div class="modal__content">
			<div v-if="objectStore.getState('theme').success !== null || objectStore.getState('theme').error">
				<NcNoteCard v-if="objectStore.getState('theme').success" type="success">
					<p>{{ isEdit ? 'Theme successfully edited' : 'Theme successfully added' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="!objectStore.getState('theme').success" type="error">
					<p>{{ isEdit ? 'Something went wrong while editing the theme' : 'Something went wrong while adding the theme' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="objectStore.getState('theme').error" type="error">
					<p>{{ objectStore.getState('theme').error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="objectStore.getState('theme').success === null" class="formContainer">
				<div class="form-group">
					<NcTextField
						:disabled="objectStore.isLoading('theme')"
						label="Title *"
						:value.sync="theme.title"
						:error="!!inputValidation.fieldErrors?.['title']"
						:helper-text="inputValidation.fieldErrors?.['title']?.[0]" />
					<NcTextField
						:disabled="objectStore.isLoading('theme')"
						label="Summary *"
						:value.sync="theme.summary"
						:error="!!inputValidation.fieldErrors?.['summary']"
						:helper-text="inputValidation.fieldErrors?.['summary']?.[0]" />
					<NcTextArea
						:disabled="objectStore.isLoading('theme')"
						label="Content (HTML)"
						:value.sync="theme.content"
						:error="!!inputValidation.fieldErrors?.['content']"
						:helper-text="inputValidation.fieldErrors?.['content']?.[0]" />
					<NcTextArea
						:disabled="objectStore.isLoading('theme')"
						label="Description"
						:value.sync="theme.description"
						:error="!!inputValidation.fieldErrors?.['description']"
						:helper-text="inputValidation.fieldErrors?.['description']?.[0]" />
					<NcTextField
						:disabled="objectStore.isLoading('theme')"
						label="Image (url)"
						:value.sync="theme.image"
						:error="!!inputValidation.fieldErrors?.['image']"
						:helper-text="inputValidation.fieldErrors?.['image']?.[0]" />
					<NcTextField
						:disabled="objectStore.isLoading('theme')"
						label="Link text (button label)"
						:value.sync="theme.link"
						placeholder="Bekijk de documenten" />
					<NcTextField
						:disabled="objectStore.isLoading('theme')"
						label="URL (destination)"
						:value.sync="theme.url"
						placeholder="https://example.com or /path" />
					<NcTextField
						:disabled="objectStore.isLoading('theme')"
						label="Icon"
						:value.sync="theme.icon"
						placeholder="e.g. raadsstuk, bestuursstuk" />
					<NcCheckboxRadioSwitch
						:checked.sync="theme.isExternal"
						:disabled="objectStore.isLoading('theme')">
						Opens in new tab (external link)
					</NcCheckboxRadioSwitch>
					<NcTextField
						:disabled="objectStore.isLoading('theme')"
						label="Sort order"
						type="number"
						:value.sync="theme.sort" />
				</div>
			</div>
			<div class="modalActions">
				<NcButton class="modalCloseButton" @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ isEdit ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton v-if="objectStore.getState('theme').success === null"
					v-tooltip="inputValidation.errorMessages?.[0]"
					:disabled="!inputValidation.success || objectStore.isLoading('theme')"
					type="primary"
					@click="saveTheme">
					<template #icon>
						<NcLoadingIcon v-if="objectStore.isLoading('theme')" :size="20" />
						<ContentSaveOutline v-if="!objectStore.isLoading('theme')" :size="20" />
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
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import { Theme } from '../../entities/index.js'
import _ from 'lodash'

export default {
	name: 'ThemeModal',
	components: {
		NcModal,
		NcTextField,
		NcTextArea,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ContentSaveOutline,
		Cancel,
	},
	data() {
		return {
			isEdit: !!objectStore.getActiveObject('theme')?.id,
			theme: {
				title: '',
				summary: '',
				description: '',
				image: '',
				content: '',
				link: '',
				url: '',
				icon: '',
				isExternal: false,
				sort: 0,
			},
			hasUpdated: false,
		}
	},
	computed: {
		inputValidation() {
			const themeItem = new Theme({
				...this.theme,
			})

			const result = themeItem.validate()

			return {
				success: result.success,
				errorMessages: result?.error?.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`) || [],
				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},
	mounted() {
		if (this.isEdit) {
			this.theme = {
				...this.theme,
				..._.cloneDeep(objectStore.getActiveObject('theme')),
			}
		}
	},
	methods: {
		closeModal() {
			if (this.closeTimeout) {
				clearTimeout(this.closeTimeout)
				this.closeTimeout = null
			}
			navigationStore.setModal(false)
			this.hasUpdated = false
			this.theme = {
				title: '',
				summary: '',
				description: '',
				image: '',
				content: '',
				link: '',
				url: '',
				icon: '',
				isExternal: false,
				sort: 0,
			}
			// Reset the object store state
			objectStore.setState('theme', { success: null, error: null })
		},
		saveTheme() {
			objectStore.setLoading('theme', true)

			const themeItem = new Theme({
				...this.theme,
			})

			const operation = this.isEdit
				? objectStore.updateObject('theme', themeItem.id, themeItem)
				: objectStore.createObject('theme', themeItem)

			operation
				.then(() => {
					objectStore.setLoading('theme', false)
					this.success = objectStore.getState('theme').success

					this.$router.push('/themes')
					// Wait for the user to read the feedback then close the model
					this.closeTimeout = setTimeout(() => {
						this.closeModal()
					}, 2000)
				})
				.catch((err) => {
					objectStore.setState('theme', { error: err })
					objectStore.setLoading('theme', false)
				})
		},
	},
}
</script>

<style>
.formContainer > * {
  margin-block-end: 10px;
}

.selectGrid {
  display: grid;
  grid-gap: 5px;
  grid-template-columns: 1fr 1fr;
}

.zaakDetailsContainer {
  margin-block-start: var(--OC-margin-20);
  margin-inline-start: var(--OC-margin-20);
  margin-inline-end: var(--OC-margin-20);
}

.success {
  color: green;
}

.APM-horizontal {
  display: flex;
  gap: 4px;
  flex-direction: row;
  align-items: center;
}
</style>
