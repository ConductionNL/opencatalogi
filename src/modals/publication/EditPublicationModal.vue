<script setup>
import { navigationStore, publicationStore } from '../../store/store.js'
</script>
<template>
	<NcDialog v-if="navigationStore.modal === 'editPublication'"
		name="Bewerk Publicatie"
		size="normal"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>Publicatie succesvol bewerkt</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>Er is iets fout gegaan bij het bewerken van Publicatie</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>
		<div v-if="success === null" class="wrapper">
			<NcTextField :disabled="loading"
				label="Titel*"
				required
				:value.sync="publicationItem.title"
				:error="!!inputValidation.fieldErrors?.['title']"
				:helper-text="inputValidation.fieldErrors?.['title']?.[0]" />
			<NcTextField :disabled="loading"
				label="Samenvatting*"
				required
				:value.sync="publicationItem.summary"
				:error="!!inputValidation.fieldErrors?.['summary']"
				:helper-text="inputValidation.fieldErrors?.['summary']?.[0]" />
			<NcTextArea :disabled="loading"
				label="Beschrijving"
				:value.sync="publicationItem.description"
				:error="!!inputValidation.fieldErrors?.['description']"
				:helper-text="inputValidation.fieldErrors?.['description']?.[0]" />
			<NcTextField :disabled="loading"
				label="Kenmerk"
				:value.sync="publicationItem.reference"
				:error="!!inputValidation.fieldErrors?.['reference']"
				:helper-text="inputValidation.fieldErrors?.['reference']?.[0]" />
			<NcTextField :disabled="loading"
				label="Categorie"
				:value.sync="publicationItem.category"
				:error="!!inputValidation.fieldErrors?.['category']"
				:helper-text="inputValidation.fieldErrors?.['category']?.[0]" />
			<NcTextField :disabled="loading"
				label="Portaal"
				:value.sync="publicationItem.portal"
				:error="!!inputValidation.fieldErrors?.['portal']"
				:helper-text="inputValidation.fieldErrors?.['portal']?.[0]" />
			<p>Publicatie datum</p>
			<NcDateTimePicker v-model="publicationItem.published"
				:disabled="loading"
				label="Publicatie datum" />
			<NcCheckboxRadioSwitch :disabled="loading"
				label="Featured"
				:checked.sync="publicationItem.featured">
				Uitgelicht
			</NcCheckboxRadioSwitch>
			<NcTextField :disabled="loading"
				label="Image"
				:value.sync="publicationItem.image"
				:error="!!inputValidation.fieldErrors?.['image']"
				:helper-text="inputValidation.fieldErrors?.['image']?.[0]" />
			<b>Juridisch</b>
			<NcTextField :disabled="loading"
				label="Licentie"
				:value.sync="publicationItem.license"
				:error="!!inputValidation.fieldErrors?.['license']"
				:helper-text="inputValidation.fieldErrors?.['license']?.[0]" />
		</div>
		<template #actions>
			<NcButton
				@click="navigationStore.setModal(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Sluiten' : 'Annuleer' }}
			</NcButton>
			<NcButton
				@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers/publicaties', '_blank')">
				<template #icon>
					<Help :size="20" />
				</template>
				Help
			</NcButton>
			<NcButton v-if="success === null"
				v-tooltip="inputValidation.errorMessages?.[0]"
				:disabled="!inputValidation.success || loading"
				type="primary"
				@click="updatePublication()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading" :size="20" />
				</template>
				Opslaan
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDateTimePicker,
	NcLoadingIcon,
	NcDialog,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Help from 'vue-material-design-icons/Help.vue'
import { Publication } from '../../entities/index.js'

export default {
	name: 'EditPublicationModal',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcCheckboxRadioSwitch,
		NcDateTimePicker,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ContentSaveOutline,
		Cancel,
		Help,
	},
	data() {
		return {
			publicationItem: {
				id: '',
				title: '',
				summary: '',
				description: '',
				reference: '',
				image: '',
				category: '',
				portal: '',
				featured: false,
				published: '',
				license: '',
				catalogi: '',
				metaData: '',
			},
			catalogi: {
				value: [],
				options: [],
			},
			metaData: {
				value: [],
				options: [],
			},
			loading: false,
			success: null,
			error: false,
			catalogiLoading: false,
			metaDataLoading: false,
			hasUpdated: false,
		}
	},
	computed: {
		inputValidation() {
			const testClass = new Publication({
				...this.publicationItem,
				catalogi: this.publicationItem.catalogi.id ?? this.publicationItem.catalogi,
				metaData: this.publicationItem.metaData.id,
				published: this.publicationItem.published !== '' ? new Date(this.publicationItem.published).toISOString() : new Date().toISOString(),
			})

			const result = testClass.validate()

			return {
				success: result.success,
				errorMessages: result?.error?.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`) || [],
				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},
	mounted() {
		// publicationStore.publicationItem can be false, so only assign publicationStore.publicationItem to publicationItem if its NOT false
		publicationStore.publicationItem && (this.publicationItem = publicationStore.publicationItem)
	},
	updated() {
		if (navigationStore.modal === 'editPublication' && this.hasUpdated) {
			if (this.publicationItem.id === publicationStore.publicationItem.id) return
			this.hasUpdated = false
		}
		if (navigationStore.modal === 'editPublication' && !this.hasUpdated) {
			publicationStore.publicationItem && (this.publicationItem = publicationStore.publicationItem)
			this.fetchData(publicationStore.publicationItem.id)
			this.hasUpdated = true
		}
	},
	methods: {
		fetchData(id) {
			this.loading = true

			publicationStore.getOnePublication(id)
				.then(({ data }) => {
					this.publicationItem = {
						...data,
						published: new Date(data.published),
					}
					this.loading = false
				})
				.catch((err) => {
					console.error(err)
					this.loading = false
				})
		},
		updatePublication() {
			this.loading = true

			const publicationItem = new Publication({
				...this.publicationItem,
				catalogi: this.publicationItem.catalogi.id ?? this.publicationItem.catalogi,
				metaData: this.publicationItem.metaData.id ?? this.publicationItem.metaData,
				published: this.publicationItem.published !== '' ? new Date(this.publicationItem.published).toISOString() : new Date().toISOString(),
			})

			publicationStore.editPublication(publicationItem)
				.then(({ response }) => {
					this.loading = false
					this.success = response.ok

					navigationStore.setSelected('publication')

					const self = this
					setTimeout(() => {
						self.success = null
						navigationStore.setModal(false)
					}, 2500)
				})
				.catch((err) => {
					this.error = err
					this.loading = false
				})
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>

<style>
.dialog__content {
  padding-top: 12px;
  padding-bottom: 12px;
}

</style>
