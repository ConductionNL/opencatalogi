<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'catalog'"
		ref="modalRef"
		:name="isEdit ? 'Catalog edit' : 'Add Catalog'"
		:label-id="isEdit ? 'editCatalogModal' : 'addCatalogModal'"
		@close="closeModal">
		<div class="modal__content">
			<div v-if="objectStore.getState('catalog').success !== null || objectStore.getState('catalog').error">
				<NcNoteCard v-if="objectStore.getState('catalog').success" type="success">
					<p>{{ isEdit ? 'Catalog successfully edited' : 'Catalog successfully added' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="!objectStore.getState('catalog').success" type="error">
					<p>{{ isEdit ? 'Something went wrong while editing the catalog' : 'Something went wrong while adding the catalog' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="objectStore.getState('catalog').error" type="error">
					<p>{{ objectStore.getState('catalog').error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="objectStore.getState('catalog').success === null && !objectStore.isLoading('catalog')" class="form-group">
				<NcTextField :disabled="objectStore.isLoading('catalog')"
					label="Title*"
					maxlength="255"
					:value.sync="catalogi.title"
					:error="!!inputValidation.fieldErrors?.['title']"
					:helper-text="inputValidation.fieldErrors?.['title']?.[0]" />
				<NcTextField :disabled="objectStore.isLoading('catalog')"
					label="Summary"
					maxlength="255"
					:value.sync="catalogi.summary"
					:error="!!inputValidation.fieldErrors?.['summary']"
					:helper-text="inputValidation.fieldErrors?.['summary']?.[0]" />
				<NcTextField :disabled="objectStore.isLoading('catalog')"
					label="Description"
					maxlength="255"
					:value.sync="catalogi.description"
					:error="!!inputValidation.fieldErrors?.['description']"
					:helper-text="inputValidation.fieldErrors?.['description']?.[0]" />
				<NcTextField :disabled="objectStore.isLoading('catalog')"
					label="Slug*"
					maxlength="255"
					:value.sync="catalogi.slug"
					:error="!!inputValidation.fieldErrors?.['slug']"
					:helper-text="inputValidation.fieldErrors?.['slug']?.[0] || 'URL-friendly identifier (e.g., publications, datasets)'"
					placeholder="publications" />
				<NcCheckboxRadioSwitch :disabled="objectStore.isLoading('catalog')"
					label="Publicly available"
					:checked.sync="catalogi.listed">
					Publicly available
				</NcCheckboxRadioSwitch>
				<NcSelect v-model="selectedOrganization"
					:options="organizationOptions"
					input-label="Organization"
					:disabled="objectStore.isLoading('catalog')" />
				<NcSelect v-model="selectedRegisters"
					:options="registerOptions"
					input-label="Registers*"
					:disabled="objectStore.isLoading('catalog')"
					multiple />
				<NcSelect v-model="selectedSchemas"
					:options="schemaOptions"
					input-label="Schemas*"
					:disabled="objectStore.isLoading('catalog')"
					multiple />
				<NcSelect v-model="catalogi.status"
					:options="statusOptions"
					:label-attribute="'label'"
					input-label="Status*"
					:disabled="objectStore.isLoading('catalog')" />
				<NcCheckboxRadioSwitch 
					:disabled="objectStore.isLoading('catalog')"
					label="Has Woo Sitemap"
					:checked.sync="catalogi.hasWooSitemap">
					Requires Woo sitemap
				</NcCheckboxRadioSwitch>
				
			</div>
			<div v-if="objectStore.isLoading('catalog')" class="loading-status">
				<NcLoadingIcon :size="20" />
				<span>{{ isEdit ? 'Catalog is being edited...' : 'Catalog is being added...' }}</span>
			</div>
			<div class="modalActions">
				<NcButton class="modalCloseButton" @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ isEdit ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton v-if="objectStore.getState('catalog').success === null && !objectStore.isLoading('catalog')"
					v-tooltip="inputValidation.errorMessages?.[0]"
					:disabled="!inputValidation.success || objectStore.isLoading('catalog')"
					type="primary"
					@click="saveCatalog">
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
import { NcButton, NcModal, NcTextField, NcLoadingIcon, NcNoteCard, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import { Catalogi } from '../../entities/index.js'
import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'CatalogModal',
	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
		// Icons
		ContentSaveOutline,
		Cancel,
	},
	data() {
		return {
			catalogi: {
				title: '',
				summary: '',
				description: '',
				slug: '',
				listed: false,
				registers: [],
				schemas: [],
				filters: {},
				status: { id: 'development', label: 'Development' },
				hasWooSitemap: false,
			},
			selectedOrganization: null,
			selectedRegisters: [],
			selectedSchemas: [],
			hasUpdated: false,
			statusOptions: [
				{ id: 'development', label: 'Development' },
				{ id: 'beta', label: 'Beta' },
				{ id: 'stable', label: 'Stable' },
				{ id: 'obsolete', label: 'Obsolete' },
			],
		}
	},
	computed: {
		isEdit() {
			return !!objectStore.getActiveObject('catalog')
		},
		organizationOptions() {
			return objectStore.getCollection('organization').results.map((organization) => ({
				id: organization.id,
				label: organization.name,
			}))
		},
		registerOptions() {
			return objectStore.availableRegisters.map(register => ({
				id: register.id,
				label: register.title,
			}))
		},
		schemaOptions() {
			// Get the selected register IDs
			const selectedRegisterIds = this.selectedRegisters.map(register => register.id)

			// Filter available registers to only those that are selected
			const selectedAvailableRegisters = objectStore.availableRegisters.filter(register =>
				selectedRegisterIds.includes(register.id),
			)

			// Get all unique schema IDs from the selected registers
			const availableSchemaIds = [...new Set(selectedAvailableRegisters.flatMap(register => register.schemas.map(schema => schema.id)))]

			// Filter and map the schemas
			return objectStore.availableSchemas
				.filter(schema => availableSchemaIds.includes(schema.id))
				.map(schema => ({
					id: schema.id,
					label: `${schema.title} (${schema.registerTitle})`,
				}))
		},
		inputValidation() {
			// Map selected objects to their IDs for validation
			const registers = this.selectedRegisters.map(register => register.id)
			const schemas = this.selectedSchemas.map(schema => schema.id)

			const status = typeof this.catalogi.status === 'object' ? this.catalogi.status.id : this.catalogi.status.toLowerCase()
			const catalogiItem = new Catalogi({
				...this.catalogi,
				status,
				organization: this.selectedOrganization?.id,
				registers,
				schemas,
				filters: {},
			})

			const result = catalogiItem.validate()

			return {
				success: result.success,
				errorMessages: result?.error?.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`) || [],
				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},
	updated() {
		if (navigationStore.modal === 'catalog' && !this.hasUpdated) {
			this.hasUpdated = true

			if (this.isEdit) {
				const activeCatalog = objectStore.getActiveObject('catalog')

				this.catalogi = {
					...activeCatalog,
					filters: Array.isArray(activeCatalog.filters) ? {} : activeCatalog.filters || {},
					status: this.statusOptions.find(opt => opt.id === (activeCatalog.status || '').toLowerCase()) || this.statusOptions[0],
				}

				// Find and set the selected organization
				const org = objectStore.getCollection('organization').results.find(
					org => org.id.toString() === activeCatalog.organization.toString(),
				)

				this.selectedOrganization = org ? { id: org.id, label: org.title } : null

				// Map existing registers and schemas to the format expected by NcSelect
				this.selectedRegisters = activeCatalog.registers.map(id => ({
					id,
					label: objectStore.availableRegisters.find(r => r.id === id)?.title || id,
				}))

				this.selectedSchemas = activeCatalog.schemas.map(id => ({
					id,
					label: objectStore.availableSchemas.find(s => s.id === id)?.title || id,
				}))
			}
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.hasUpdated = false
			this.catalogi = {
				title: '',
				summary: '',
				description: '',
				slug: '',
				listed: false,
				registers: [],
				schemas: [],
				filters: {},
				status: { id: 'development', label: 'Development' },
				hasWooSitemap: false,
			}
			this.selectedOrganization = null
			this.selectedRegisters = []
			this.selectedSchemas = []
			// Reset the object store state
			objectStore.setState('catalog', { success: null, error: null })
		},
		saveCatalog() {
			// Map selected objects to their IDs for saving
			const registers = this.selectedRegisters.map(register => register.id)
			const schemas = this.selectedSchemas.map(schema => schema.id)

			const status = typeof this.catalogi.status === 'object' ? this.catalogi.status.id : this.catalogi.status.toLowerCase()
			const catalogiItem = new Catalogi({
				...this.catalogi,
				status,
				organization: this.selectedOrganization?.id,
				registers,
				schemas,
				filters: {},
			})

			if (this.isEdit) {
				objectStore.updateObject('catalog', catalogiItem.id, catalogiItem)
					.then(() => {
						// Wait for the user to read the feedback then close the model
						const self = this
						setTimeout(function() {
							self.closeModal()
						}, 2000)
					})
			} else {
				delete catalogiItem.id

				objectStore.createObject('catalog', catalogiItem)
					.then(() => {
						// Wait for the user to read the feedback then close the model
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

.zaakDetailsContainer {
    margin-block-start: var(--OC-margin-20);
    margin-inline-start: var(--OC-margin-20);
    margin-inline-end: var(--OC-margin-20);
}

.success {
    color: green;
}

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
}
</style>
