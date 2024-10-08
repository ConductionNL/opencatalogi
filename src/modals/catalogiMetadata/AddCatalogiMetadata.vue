<script setup>
import { catalogiStore, navigationStore, metadataStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'addCatalogiMetadata'"
		ref="modalRef"
		label-id="addCatalogiMetadata"
		@close="closeModal">
		<div class="modal__content">
			<h2>Publicatietype toevoegen aan {{ catalogiItem.title }}</h2>
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>Publicatietype succesvol toegevoegd</p>
				</NcNoteCard>
				<NcNoteCard v-if="!success" type="error">
					<p>Er is iets fout gegaan bij het toevoegen van een publicatietype</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="success === null" class="form-group">
				<NcSelect v-bind="metaData"
					v-model="metaData.value"
					input-label="Publicatietype"
					:loading="metaDataLoading"
					required />
			</div>
			<NcButton v-if="success === null"
				:disabled="!metaData?.value || loading"
				type="primary"
				@click="addCatalogMetadata">
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
import { NcButton, NcModal, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import { Catalogi } from '../../entities/index.js'

export default {
	name: 'AddCatalogiMetadata',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		// Icons
		Plus,
	},
	data() {
		return {
			catalogiItem: {
				title: '',
				summary: '',
				description: '',
				listed: false,
			},
			metaData: {},
			metaDataLoading: false,
			loading: false,
			success: null,
			error: false,
			errorCode: '',
			hasUpdated: false,
		}
	},
	mounted() {
		// catalogiStore.catalogiItem can be false, so only assign catalogiStore.catalogiItem to catalogiItem if its NOT false
		catalogiStore.catalogiItem && (this.catalogiItem = catalogiStore.catalogiItem)
	},
	updated() {
		if (navigationStore.modal === 'addCatalogiMetadata' && this.hasUpdated) {
			if (this.catalogiItem.id === catalogiStore.catalogiItem.id) return
			this.hasUpdated = false
		}
		if (navigationStore.modal === 'addCatalogiMetadata' && !this.hasUpdated) {
			catalogiStore.catalogiItem && (this.catalogiItem = catalogiStore.catalogiItem)
			this.fetchData(catalogiStore.catalogiItem.id)
			this.fetchMetaData(catalogiStore.catalogiItem?.metadata || [])
			this.hasUpdated = true
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.catalogi = {
				title: '',
				summary: '',
				description: '',
				listed: false,
			}
		},
		fetchData(id) {
			this.loading = true

			catalogiStore.getOneCatalogi(id)
				.then(({ response, data }) => {
					this.catalogiItem = catalogiStore.catalogiItem

					this.loading = false
				})
				.catch((err) => {
					console.error(err)
					this.loading = false
				})
		},
		fetchMetaData(metadataList) {
			this.metaDataLoading = true

			metadataStore.getAllMetadata()
				.then(({ response, data }) => {

					const filteredData = data.filter((meta) => !metadataList.includes(meta?.source))

					this.metaData = {
						options: filteredData.map((metaData) => ({
							source: metaData.source,
							id: metaData.id,
							label: metaData.title,
						})),
					}

					this.metaDataLoading = false
				})
				.catch((err) => {
					console.error(err)
					this.metaDataLoading = false
				})
		},
		addCatalogMetadata() {
			this.loading = true
			this.error = false

			this.catalogiItem.metadata.push(this.metaData.value.source !== '' ? this.metaData.value.source : this.metaData.value.id)

			const newCatalogiItem = new Catalogi({
				...this.catalogiItem,
				metadata: this.catalogiItem.metadata,
			})

			catalogiStore.editCatalogi(newCatalogiItem)
				.then(({ response }) => {
					this.loading = false
					this.success = response.ok

					// Wait for the user to read the feedback then close the model
					const self = this
					setTimeout(function() {
						self.success = null
						self.closeModal()
					}, 2000)

					this.hasUpdated = false
				})
				.catch((err) => {
					this.error = err
					this.loading = false
					this.hasUpdated = false
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
