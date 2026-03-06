/**
 * GlossaryModal.vue
 * Modal component for creating and editing glossary items
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
	<NcModal v-if="navigationStore.modal === 'glossary'"
		ref="modalRef"
		:name="isEdit ? 'Edit term' : 'Add term'"
		:label-id="isEdit ? 'editGlossaryModal' : 'addGlossaryModal'"
		@close="closeModal">
		<div class="modal__content">
			<div v-if="objectStore.getState('glossary').success !== null || objectStore.getState('glossary').error">
				<NcNoteCard v-if="objectStore.getState('glossary').success" type="success">
					<p>{{ isEdit ? 'Term successfully edited' : 'Term successfully added' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="!objectStore.getState('glossary').success" type="error">
					<p>{{ isEdit ? 'Something went wrong while editing the term' : 'Something went wrong while adding the term' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="objectStore.getState('glossary').error" type="error">
					<p>{{ objectStore.getState('glossary').error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="objectStore.getState('glossary').success === null && !objectStore.isLoading('glossary')" class="form-group">
				<NcTextField :disabled="objectStore.isLoading('glossary')"
					label="Title*"
					maxlength="255"
					:value.sync="glossary.title"
					:error="!!inputValidation.fieldErrors?.['title']"
					:helper-text="inputValidation.fieldErrors?.['title']?.[0]" />
				<NcTextField :disabled="objectStore.isLoading('glossary')"
					label="Summary"
					maxlength="255"
					:value.sync="glossary.summary"
					:error="!!inputValidation.fieldErrors?.['summary']"
					:helper-text="inputValidation.fieldErrors?.['summary']?.[0]" />
				<NcTextField :disabled="objectStore.isLoading('glossary')"
					label="Description"
					type="textarea"
					:value.sync="glossary.description"
					:error="!!inputValidation.fieldErrors?.['description']"
					:helper-text="inputValidation.fieldErrors?.['description']?.[0]" />
				<NcTextField :disabled="objectStore.isLoading('glossary')"
					label="External link"
					:value.sync="glossary.externalLink"
					:error="!!inputValidation.fieldErrors?.['externalLink']"
					:helper-text="inputValidation.fieldErrors?.['externalLink']?.[0]" />
				<NcSelectTags v-model="glossary.keywords"
					:disabled="objectStore.isLoading('glossary')"
					label="Keywords"
					:error="!!inputValidation.fieldErrors?.['keywords']"
					:helper-text="inputValidation.fieldErrors?.['keywords']?.[0]"
					placeholder="Add keywords" />
			</div>
			<div v-if="objectStore.isLoading('glossary')" class="loading-status">
				<NcLoadingIcon :size="20" />
				<span>{{ isEdit ? 'Term is being edited...' : 'Term is being added...' }}</span>
			</div>
			<div class="modalActions">
				<NcButton class="modalCloseButton" @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ isEdit ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton v-if="objectStore.getState('glossary').success === null && !objectStore.isLoading('glossary')"
					v-tooltip="inputValidation.errorMessages?.[0]"
					:disabled="!inputValidation.success || objectStore.isLoading('glossary')"
					type="primary"
					@click="saveGlossary">
					<template #icon>
						<ContentSaveOutline :size="20" />
					</template>
					{{ isEdit ? 'Save' : 'Add' }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcTextField, NcLoadingIcon, NcNoteCard, NcSelectTags } from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import { Glossary } from '../../entities/index.js'

export default {
	name: 'GlossaryModal',
	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelectTags,
		ContentSaveOutline,
		Cancel,
	},
	data() {
		return {
			glossary: {
				title: '',
				summary: '',
				description: '',
				externalLink: '',
				keywords: [],
			},
			hasUpdated: false,
		}
	},
	computed: {
		isEdit() {
			return !!objectStore.getActiveObject('glossary')
		},
		inputValidation() {
			const glossaryItem = new Glossary(this.glossary)
			const result = glossaryItem.validate()

			return {
				success: result.success,
				errorMessages: result?.error?.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`) || [],
				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},
	updated() {
		if (navigationStore.modal === 'glossary' && !this.hasUpdated) {
			if (this.isEdit) {
				const activeGlossary = objectStore.getActiveObject('glossary')
				this.glossary = { ...activeGlossary }
			}
			this.hasUpdated = true
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.hasUpdated = false
			this.glossary = {
				title: '',
				summary: '',
				description: '',
				externalLink: '',
				keywords: [],
			}
			objectStore.setState('glossary', { success: null, error: null })
		},
		saveGlossary() {
			const glossaryItem = new Glossary(this.glossary)

			if (this.isEdit) {
				objectStore.updateObject('glossary', glossaryItem.id, glossaryItem)
					.then(() => {
						const self = this
						setTimeout(function() {
							self.closeModal()
						}, 2000)
					})
			} else {
				objectStore.createObject('glossary', glossaryItem)
					.then(() => {
						const self = this
						setTimeout(function() {
							self.closeModal()
						}, 2000)
					})
			}
		},
	},
}
</script>

<style>
.loading-status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin: 1rem 0;
    color: var(--color-text-lighter);
}
</style>

<style scoped>
.form-group {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-10);
}
</style>
