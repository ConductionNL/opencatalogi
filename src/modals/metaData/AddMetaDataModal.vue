<script setup>
import { navigationStore, metadataStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'addMetaData'"
		ref="modalRef"
		label-id="addMetaDataModal"
		@close="navigationStore.setModal(false)">
		<div class="modal__content">
			<h2>MetaData toevoegen</h2>
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>Metadata succesvol toegevoegd</p>
				</NcNoteCard>
				<NcNoteCard v-if="!success" type="error">
					<p>Er is iets fout gegaan bij het toevoegen van metadata</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="success === null" class="form-group">
				<NcTextField label="Titel" :value.sync="metaData.title" required="true" />
				<NcTextField label="Versie" :value.sync="metaData.version" />
				<NcTextArea label="Beschrijving" :disabled="loading" :value.sync="metaData.description" />
			</div>
			<NcButton v-if="success === null"
				:disabled="!metaData.title || loading"
				type="primary"
				@click="addMetaData">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Plus v-if="!loading" :size="20" />
				</template>
				Toevoegen
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcTextField, NcTextArea, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'AddMetaDataModal',
	components: {
		NcModal,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		Plus,
	},
	data() {
		return {

			metaData: {
				title: '',
				version: '',
				description: '',
				required: '',
			},
			metaDataList: [],
			loading: false,
			success: null,
			error: false,
		}
	},
	methods: {
		addMetaData() {
			this.loading = true
			fetch(
				'/index.php/apps/opencatalogi/api/metadata',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						...this.metaData,
					}),
				},
			)
				.then((response) => {
					// Set the form
					this.loading = false
					this.success = response.ok
					// Lets refresh the catalogiList
					metadataStore.refreshMetaDataList()
					response.json().then((data) => {
						metadataStore.setMetaDataItem(data)
					})
					navigationStore.setSelected('metaData')
					// Update the list
					const self = this
					setTimeout(function() {
						self.success = null
						this.metaData = {
							title: '',
							version: '',
							description: '',
							required: '',
						}
						navigationStore.setModal(false)
					}, 2000)
				})
				.catch((err) => {
					this.metaDataLoading = false
					this.error = err
					console.error(err)
				})
		},
	},
}
</script>

<style>
.modal__content {
    margin: var(--OC-margin-50);
    text-align: center;
}

.zaakDetailsContainer {
    margin-block-start: var(--OC-margin-20);
    margin-inline-start: var(--OC-margin-20);
    margin-inline-end: var(--OC-margin-20);
}

.success {
    color: green;
}
</style>
