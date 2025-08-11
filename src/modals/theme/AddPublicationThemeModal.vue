<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal
		v-if="navigationStore.modal === 'addPublicationTheme'"
		ref="modalRef"
		label-id="addPublicationThemeModal"
		@close="closeModal">
		<div class="modal__content">
			<h2>Add publication theme</h2>

			<NcNoteCard v-if="successState" type="success">
				<p>Theme successfully saved</p>
			</NcNoteCard>
			<NcNoteCard v-if="errorState" type="error">
				<p>{{ errorState }}</p>
			</NcNoteCard>

			<div v-if="successState === null" class="selectWrapper">
				<NcSelect
					:items="themeOptions"
					:value.sync="selectedTheme"
					label="Choose a theme"
					:disabled="isSaving || !themeOptions.length" />
			</div>

			<NcButton
				v-if="successState === null"
				type="primary"
				:disabled="!selectedTheme || isSaving"
				class="save-button"
				@click="saveTheme">
				<template #icon>
					<NcLoadingIcon v-if="isSaving" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				Save
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcSelect,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'

export default {
	name: 'AddPublicationThemeModal',
	components: {
		NcModal,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		ContentSaveOutline,
	},
	data() {
		return {
			selectedTheme: null,
			isSaving: false,
			successState: null,
			errorState: null,
		}
	},
	computed: {
		themes() {
			return objectStore.getCollection('theme').results || []
		},
		themeOptions() {
			const publication = objectStore.getActiveObject('publication')
			return this.themes
				.filter(t => !publication?.theme?.includes(t.id))
				.map(t => ({
					value: t.id,
					label: t.title || `#${t.id}`,
				}))
		},
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.successState = null
			this.errorState = null
			this.selectedTheme = null
		},
		async saveTheme() {
			if (!this.selectedTheme) return
			this.isSaving = true
			const publication = objectStore.getActiveObject('publication')
			if (!publication) {
				this.errorState = 'No publication selected'
				this.isSaving = false
				return
			}
			try {
				const updatedPublication = {
					...publication,
					theme: this.selectedTheme,
				}
				await objectStore.updateObject('publication', publication.id, updatedPublication)
				await objectStore.fetchCollection('theme')
				this.successState = true
				this.errorState = null
				setTimeout(this.closeModal, 1500)
			} catch (e) {
				this.errorState = e.message
				this.successState = false
			} finally {
				this.isSaving = false
			}
		},
	},
}
</script>

<style>
.modal__content {
  margin: var(--OC-margin-50);
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.selectWrapper {
  display: flex;
  justify-content: center;
}
</style>

<style scoped>
.save-button {
  margin-left: auto;
}
</style>
