<template>
	<div>
		<NcSettingsSection :name="'Open Catalogi'" description="Eén centrale plek voor hergebruik van informatietechnologie binnen de overheid" doc-url="https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers" />
		<NcSettingsSection :name="'Data storage'" description="Korte uitleg over dat je kan opslaan in de nextcloud database of open registers en via open registers ook in externe opslag zo al mongo db">
			<div v-if="!loading">
				<div v-if="!openRegisterInstalled">
					<NcNoteCard type="info">
						Je hebt nog geen Open Registers geïnstalleerd, we raden je aan om dat wel te doen.
					</NcNoteCard>

					<NcButton
						type="primary"
						@click="openLink('/index.php/settings/apps/organization/openregister', '_blank')">
						<template #icon>
							<NcLoadingIcon v-if="loading || saving" :size="20" />
							<Restart v-if="!loading && !saving" :size="20" />
						</template>
						Installeer Open Registers
					</NcButton>
				</div>

				<div v-if="!openRegisterInstalled && (settingsData.publication_source === 'openregister' || settingsData.publicationtype_source === 'openregister' || settingsData.catalog_source === 'openregister' || settingsData.listing_source === 'openregister' || settingsData.attachment_source === 'openregister' || settingsData.organization_source === 'openregister' || settingsData.theme_source === 'openregister' || settingsData.page_source === 'openregister' || settingsData.menu_source === 'openregister')">
					<NcNoteCard type="warning">
						Het lijkt erop dat je een open register hebt geselecteerd maar dat deze nog niet geïnstalleerd is. Dit kan problemen geven. Wil je de instelling resetten?
					</NcNoteCard>
					<NcButton
						type="primary"
						@click="resetConfig()">
						<template #icon>
							<NcLoadingIcon v-if="loading || saving" :size="20" />
							<Restart v-if="!loading && !saving" :size="20" />
						</template>
						Reset
					</NcButton>
				</div>

				<h3>Publicatie</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="publication.selectedSource"
						required
						input-label="Source"
						:loading="publication.loading"
						:disabled="loading || publication.loading" />

					<NcSelect v-if="publication.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="publication.selectedRegister"
						input-label="Register"
						:loading="publication.loading"
						:disabled="loading || publication.loading" />

					<NcSelect v-if="publication.selectedSource?.value === 'openregister' && publication.selectedRegister?.value"
						v-bind="publication.availableSchemas"
						v-model="publication.selectedSchema"
						input-label="Schema"
						:loading="publication.loading"
						:disabled="loading || publication.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || publication.loading || !publication.selectedSource?.value || publication.selectedSource?.value === 'openregister' && (!publication.selectedRegister?.value || !publication.selectedSchema?.value)"
						@click="saveConfig('publication')">
						<template #icon>
							<NcLoadingIcon v-if="loading || publication.loading" :size="20" />
							<Plus v-if="!loading && !publication.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Organisatie</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="organization.selectedSource"
						required
						input-label="Source"
						:loading="organization.loading"
						:disabled="loading || organization.loading" />

					<NcSelect v-if="organization.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="organization.selectedRegister"
						input-label="Register"
						:loading="organization.loading"
						:disabled="loading || organization.loading" />

					<NcSelect v-if="organization.selectedSource?.value === 'openregister' && organization.selectedRegister?.value"
						v-bind="organization.availableSchemas"
						v-model="organization.selectedSchema"
						input-label="Schema"
						:loading="organization.loading"
						:disabled="loading || organization.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || organization.loading || !organization.selectedSource?.value || organization.selectedSource?.value === 'openregister' && (!organization.selectedRegister?.value || !organization.selectedSchema?.value)"
						@click="saveConfig('organization')">
						<template #icon>
							<NcLoadingIcon v-if="loading || organization.loading" :size="20" />
							<Plus v-if="!loading && !organization.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Bijlagen</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="attachment.selectedSource"
						required
						input-label="Source"
						:loading="attachment.loading"
						:disabled="loading || attachment.loading" />

					<NcSelect v-if="attachment.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="attachment.selectedRegister"
						input-label="Register"
						:loading="attachment.loading"
						:disabled="loading || attachment.loading" />

					<NcSelect v-if="attachment.selectedSource?.value === 'openregister' && attachment.selectedRegister?.value"
						v-bind="attachment.availableSchemas"
						v-model="attachment.selectedSchema"
						input-label="Schema"
						:loading="attachment.loading"
						:disabled="loading || attachment.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || attachment.loading || !attachment.selectedSource?.value || attachment.selectedSource?.value === 'openregister' && (!attachment.selectedRegister?.value || !attachment.selectedSchema?.value)"
						@click="saveConfig('attachment')">
						<template #icon>
							<NcLoadingIcon v-if="loading || attachment.loading" :size="20" />
							<Plus v-if="!loading && !attachment.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Catalogus</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="catalog.selectedSource"
						required
						input-label="Source"
						:loading="catalog.loading"
						:disabled="loading || catalog.loading" />

					<NcSelect v-if="catalog.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="catalog.selectedRegister"
						input-label="Register"
						:loading="catalog.loading"
						:disabled="loading || catalog.loading" />

					<NcSelect v-if="catalog.selectedSource?.value === 'openregister' && catalog.selectedRegister?.value"
						v-bind="catalog.availableSchemas"
						v-model="catalog.selectedSchema"
						input-label="Schema"
						:loading="catalog.loading"
						:disabled="loading || catalog.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || catalog.loading || !catalog.selectedSource?.value || catalog.selectedSource?.value === 'openregister' && (!catalog.selectedRegister?.value || !catalog.selectedSchema?.value)"
						@click="saveConfig('catalog')">
						<template #icon>
							<NcLoadingIcon v-if="loading || catalog.loading" :size="20" />
							<Plus v-if="!loading && !catalog.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Directory</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="listing.selectedSource"
						required
						input-label="Source"
						:loading="listing.loading"
						:disabled="loading || listing.loading" />

					<NcSelect v-if="listing.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="listing.selectedRegister"
						input-label="Register"
						:loading="listing.loading"
						:disabled="loading || listing.loading" />

					<NcSelect v-if="listing.selectedSource?.value === 'openregister' && listing.selectedRegister?.value"
						v-bind="listing.availableSchemas"
						v-model="listing.selectedSchema"
						input-label="Schema"
						:loading="listing.loading"
						:disabled="loading || listing.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || listing.loading || !listing.selectedSource?.value || listing.selectedSource?.value === 'openregister' && (!listing.selectedRegister?.value || !listing.selectedSchema?.value)"
						@click="saveConfig('listing')">
						<template #icon>
							<NcLoadingIcon v-if="loading || listing.loading" :size="20" />
							<Plus v-if="!loading && !listing.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Thema</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="theme.selectedSource"
						required
						input-label="Source"
						:loading="theme.loading"
						:disabled="loading || theme.loading" />

					<NcSelect v-if="theme.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="theme.selectedRegister"
						input-label="Register"
						:loading="theme.loading"
						:disabled="loading || theme.loading" />

					<NcSelect v-if="theme.selectedSource?.value === 'openregister' && theme.selectedRegister?.value"
						v-bind="theme.availableSchemas"
						v-model="theme.selectedSchema"
						input-label="Schema"
						:loading="theme.loading"
						:disabled="loading || theme.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || theme.loading || !theme.selectedSource?.value || theme.selectedSource?.value === 'openregister' && (!theme.selectedRegister?.value || !theme.selectedSchema?.value)"
						@click="saveConfig('theme')">
						<template #icon>
							<NcLoadingIcon v-if="loading || theme.loading" :size="20" />
							<Plus v-if="!loading && !theme.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Pagina</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="page.selectedSource"
						required
						input-label="Source"
						:loading="page.loading"
						:disabled="loading || page.loading" />

					<NcSelect v-if="page.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="page.selectedRegister"
						input-label="Register"
						:loading="page.loading"
						:disabled="loading || page.loading" />

					<NcSelect v-if="page.selectedSource?.value === 'openregister' && page.selectedRegister?.value"
						v-bind="page.availableSchemas"
						v-model="page.selectedSchema"
						input-label="Schema"
						:loading="page.loading"
						:disabled="loading || page.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || page.loading || !page.selectedSource?.value || page.selectedSource?.value === 'openregister' && (!page.selectedRegister?.value || !page.selectedSchema?.value)"
						@click="saveConfig('page')">
						<template #icon>
							<NcLoadingIcon v-if="loading || page.loading" :size="20" />
							<Plus v-if="!loading && !page.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Menu</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="menu.selectedSource"
						required
						input-label="Source"
						:loading="menu.loading"
						:disabled="loading || menu.loading" />

					<NcSelect v-if="menu.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="menu.selectedRegister"
						input-label="Register"
						:loading="menu.loading"
						:disabled="loading || menu.loading" />

					<NcSelect v-if="menu.selectedSource?.value === 'openregister' && menu.selectedRegister?.value"
						v-bind="menu.availableSchemas"
						v-model="menu.selectedSchema"
						input-label="Schema"
						:loading="menu.loading"
						:disabled="loading || menu.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || menu.loading || !menu.selectedSource?.value || menu.selectedSource?.value === 'openregister' && (!menu.selectedRegister?.value || !menu.selectedSchema?.value)"
						@click="saveConfig('menu')">
						<template #icon>
							<NcLoadingIcon v-if="loading || menu.loading" :size="20" />
							<Plus v-if="!loading && !menu.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<h3>Publicatie Type</h3>
				<div class="selectionContainer">
					<NcSelect v-bind="labelOptions"
						v-model="publicationtype.selectedSource"
						required
						input-label="Source"
						:loading="publicationtype.loading"
						:disabled="loading || publicationtype.loading" />

					<NcSelect v-if="publicationtype.selectedSource?.value === 'openregister' "
						v-bind="availableRegistersOptions"
						v-model="publicationtype.selectedRegister"
						input-label="Register"
						:loading="publicationtype.loading"
						:disabled="loading || publicationtype.loading" />

					<NcSelect v-if="publicationtype.selectedSource?.value === 'openregister' && publicationtype.selectedRegister?.value"
						v-bind="publicationtype.availableSchemas"
						v-model="publicationtype.selectedSchema"
						input-label="Schema"
						:loading="publicationtype.loading"
						:disabled="loading || publicationtype.loading" />

					<NcButton
						type="primary"
						:disabled="loading || saving || publicationtype.loading || !publicationtype.selectedSource?.value || publicationtype.selectedSource?.value === 'openregister' && (!publicationtype.selectedRegister?.value || !publicationtype.selectedSchema?.value)"
						@click="saveConfig('publicationtype')">
						<template #icon>
							<NcLoadingIcon v-if="loading || publicationtype.loading" :size="20" />
							<Plus v-if="!loading && !publicationtype.loading" :size="20" />
						</template>
						Opslaan
					</NcButton>
				</div>

				<NcButton
					type="primary"
					:disabled="saving"
					@click="saveAll()">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<Plus v-if="!saving" :size="20" />
					</template>
					Alles opslaan
				</NcButton>
			</div>
			<NcLoadingIcon v-if="loading"
				class="loadingIcon"
				:size="64"
				appearance="dark"
				name="Settings aan het laden" />
		</NcSettingsSection>
	</div>
</template>

<script>
// Components
import { NcSettingsSection, NcNoteCard, NcSelect, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Restart from 'vue-material-design-icons/Restart.vue'

export default {
	name: 'AdminSettings',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		Plus,
		Restart,
	},
	data() {
		return {
			loading: false,
			openRegisterInstalled: false,
			initialization: false,
			saving: false,
			settingsData: {},
			availableRegisters: [],
			availableRegistersOptions: [],
			objectTypes: [],
			publication: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			publicationtype: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			organization: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			catalog: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			listing: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			attachment: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			theme: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			page: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			menu: {
				selectedSource: '',
				selectedRegister: '',
				selectedSchema: '',
				availableSchemas: [],
				loading: false,
			},
			labelOptions: {
				options: [
					{ label: 'Internal', value: 'internal' },
					{ label: 'OpenRegister', value: 'openregister' },
				],
			},
		}
	},

	watch: {
		'publication.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {

					this.publication.selectedRegister = ''
					this.publication.selectedSchema = ''
				}
			},
			deep: true,
		},
		'publication.selectedRegister': {
			handler(newValue, oldValue) {

				if (this.initialization === true && oldValue === '') return
				if (newValue) {
					this.setRegisterSchemaOptions(newValue?.value, 'publication')
					oldValue !== '' && newValue?.value !== oldValue.value && (this.publication.selectedSchema = '')
				}
			},
			deep: true,
		},
		'organization.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {

					this.organization.selectedRegister = ''
					this.organization.selectedSchema = ''
				}
			},
			deep: true,
		},
		'organization.selectedRegister': {
			handler(newValue, oldValue) {

				if (this.initialization === true && oldValue === '') return
				if (newValue) {
					this.setRegisterSchemaOptions(newValue?.value, 'organization')
					oldValue !== '' && newValue?.value !== oldValue.value && (this.organization.selectedSchema = '')
				}
			},
			deep: true,
		},
		'listing.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {

					this.listing.selectedRegister = ''
					this.listing.selectedSchema = ''
				}
			},
			deep: true,
		},
		'listing.selectedRegister': {
			handler(newValue, oldValue) {

				if (this.initialization === true && oldValue === '') return
				if (newValue) {
					this.setRegisterSchemaOptions(newValue?.value, 'listing')
					oldValue !== '' && newValue?.value !== oldValue.value && (this.listing.selectedSchema = '')
				}
			},
			deep: true,
		},
		'attachment.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {

					this.attachment.selectedRegister = ''
					this.attachment.selectedSchema = ''
				}
			},
			deep: true,
		},
		'attachment.selectedRegister': {
			handler(newValue, oldValue) {

				if (this.initialization === true && oldValue === '') return
				if (newValue) {
					this.setRegisterSchemaOptions(newValue?.value, 'attachment')
					oldValue !== '' && newValue?.value !== oldValue.value && (this.attachment.selectedSchema = '')
				}
			},
			deep: true,
		},
		'theme.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {

					this.theme.selectedRegister = ''
					this.theme.selectedSchema = ''
				}
			},
			deep: true,
		},
		'theme.selectedRegister': {
			handler(newValue, oldValue) {

				if (this.initialization === true && oldValue === '') return
				if (newValue) {
					this.setRegisterSchemaOptions(newValue?.value, 'theme')
					oldValue !== '' && newValue?.value !== oldValue.value && (this.theme.selectedSchema = '')
				}
			},
			deep: true,
		},
		'page.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {
					// Reset register and schema when source is internal
					this.page.selectedRegister = ''
					this.page.selectedSchema = ''
				}
			},
			deep: true,
		},
		'page.selectedRegister': {
			handler(newValue, oldValue) {
				// Skip initialization
				if (this.initialization === true && oldValue === '') return

				if (newValue) {
					// Set schema options for selected register
					this.setRegisterSchemaOptions(newValue?.value, 'page')
					// Reset schema if register changed
					oldValue !== '' && newValue?.value !== oldValue.value && (this.page.selectedSchema = '')
				}
			},
			deep: true,
		},
		'menu.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {
					// Reset register and schema when source is internal
					this.menu.selectedRegister = ''
					this.menu.selectedSchema = ''
				}
			},
			deep: true,
		},
		'menu.selectedRegister': {
			handler(newValue, oldValue) {
				// Skip initialization
				if (this.initialization === true && oldValue === '') return

				if (newValue) {
					// Set schema options for selected register
					this.setRegisterSchemaOptions(newValue?.value, 'menu')
					// Reset schema if register changed
					oldValue !== '' && newValue?.value !== oldValue.value && (this.menu.selectedSchema = '')
				}
			},
			deep: true,
		},
		'publicationtype.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {

					this.publicationtype.selectedRegister = ''
					this.publicationtype.selectedSchema = ''
				}
			},
			deep: true,
		},
		'publicationtype.selectedRegister': {
			handler(newValue, oldValue) {

				if (this.initialization === true && oldValue === '') return
				if (newValue) {
					this.setRegisterSchemaOptions(newValue?.value, 'publicationtype')
					oldValue !== '' && newValue?.value !== oldValue.value && (this.publicationtype.selectedSchema = '')
				}
			},
			deep: true,
		},
		'catalog.selectedSource': {
			handler(newValue) {
				if (newValue?.value === 'internal') {

					this.catalog.selectedRegister = ''
					this.catalog.selectedSchema = ''
				}
			},
			deep: true,
		},
		'catalog.selectedRegister': {
			handler(newValue, oldValue) {

				if (this.initialization === true && oldValue === '') return
				if (newValue) {
					this.setRegisterSchemaOptions(newValue?.value, 'catalog')
					oldValue !== '' && newValue?.value !== oldValue.value && (this.catalog.selectedSchema = '')
				}
			},
			deep: true,
		},
	},
	mounted() {
		this.fetchAll()
	},
	methods: {
		setRegisterSchemaOptions(registerId, property) {
			const selectedRegister = this.availableRegisters.find((register) => register.id.toString() === registerId)

			this[property].availableSchemas = {
				options: selectedRegister?.schemas?.map((schema) => ({
					value: schema.id.toString(),
					label: schema.title,
				})),
			}

		},
		fetchAll() {
			this.loading = true

			fetch('/index.php/apps/opencatalogi/settings',
				{
					method: 'GET',
				},
			)
				.then((response) => {
					this.initialization = true
					response.json().then((data) => {
						this.openRegisterInstalled = data.openRegisters
						this.settingsData = data
						this.availableRegisters = data.availableRegisters
						this.objectTypes = data.objectTypes

						this.availableRegistersOptions = {
							options: data.availableRegisters.map((register) => ({
								value: register.id.toString(),
								label: register.title,
							})),
						}

						data.objectTypes.forEach((objectType) => {

							if (data[objectType + '_register']) {
								this.setRegisterSchemaOptions(data[objectType + '_register'], objectType)
							}
							this[objectType] = {
								selectedSource: this.labelOptions.options.find((option) => option.value === data[objectType + '_source'] ?? data[objectType + '_source']),
								selectedRegister: this.availableRegistersOptions.options.find((option) => option.value === data[objectType + '_register']),
								selectedSchema: this[objectType]?.availableSchemas?.options?.find((option) => option.value === data[objectType + '_schema']),
							}
						})

						this.initialization = false
						this.loading = false

					})
				})
				.catch((err) => {
					console.error(err)
					this.initialization = false
					this.loading = false
					return err
				})
		},
		saveConfig(configId) {
			this[configId].loading = true
			this.saving = true
			console.info(`Saving ${configId} config`)

			const settingsDataCopy = this.settingsData

			delete settingsDataCopy.objectTypes
			delete settingsDataCopy.openRegisters
			delete settingsDataCopy.availableRegisters

			fetch('/index.php/apps/opencatalogi/settings',
				{
					method: 'POST',
					body: JSON.stringify({
						...settingsDataCopy,
						[configId + '_register']: this[configId].selectedRegister?.value ?? '',
						[configId + '_schema']: this[configId].selectedSchema?.value ?? '',
						[configId + '_source']: this[configId].selectedSource?.value ?? 'internal',
					}),
					headers: {
						'Content-Type': 'application/json',
					},
				},
			)
				.then((response) => {
					response.json().then((data) => {
						this[configId].loading = false
						this.saving = false

						this.settingsData = {
							...this.settingsData,
							[configId + '_register']: data[configId + '_register'],
							[configId + '_schema']: data[configId + '_schema'],
							[configId + '_source']: data[configId + '_source'],
						}

					})
				})
				.catch((err) => {
					console.error(err)
					this[configId].loading = false
					this.saving = false
					return err
				})
		},

		saveAll() {
			this.saving = true
			this.objectTypes.forEach((objectType) => {
				this[objectType].loading = true
			})

			console.info('Saving all config')

			const settingsDataCopy = this.settingsData

			delete settingsDataCopy.objectTypes
			delete settingsDataCopy.openRegisters
			delete settingsDataCopy.availableRegisters

			fetch('/index.php/apps/opencatalogi/settings',
				{
					method: 'POST',
					body: JSON.stringify({
						...settingsDataCopy,
						attachment_register: this.attachment.selectedRegister?.value ?? '',
						attachment_schema: this.attachment.selectedSchema?.value ?? '',
						attachment_source: this.attachment.selectedSource?.value ?? 'internal',
						catalog_register: this.catalog.selectedRegister?.value ?? '',
						catalog_schema: this.catalog.selectedSchema?.value ?? '',
						catalog_source: this.catalog.selectedSource?.value ?? 'internal',
						listing_register: this.listing.selectedRegister?.value ?? '',
						listing_schema: this.listing.selectedSchema?.value ?? '',
						listing_source: this.listing.selectedSource?.value ?? 'internal',
						organization_register: this.organization.selectedRegister?.value ?? '',
						organization_schema: this.organization.selectedSchema?.value ?? '',
						organization_source: this.organization.selectedSource?.value ?? 'internal',
						publication_register: this.publication.selectedRegister?.value ?? '',
						publication_schema: this.publication.selectedSchema?.value ?? '',
						publication_source: this.publication.selectedSource?.value ?? 'internal',
						publicationtype_register: this.publicationtype.selectedRegister?.value ?? '',
						publicationtype_schema: this.publicationtype.selectedSchema?.value ?? '',
						publicationtype_source: this.publicationtype.selectedSource?.value ?? 'internal',
						theme_register: this.theme.selectedRegister?.value ?? '',
						theme_schema: this.theme.selectedSchema?.value ?? '',
						theme_source: this.theme.selectedSource?.value ?? 'internal',
						page_register: this.page.selectedRegister?.value ?? '',
						page_schema: this.page.selectedSchema?.value ?? '',
						page_source: this.page.selectedSource?.value ?? 'internal',
						menu_register: this.menu.selectedRegister?.value ?? '',
						menu_schema: this.menu.selectedSchema?.value ?? '',
						menu_source: this.menu.selectedSource?.value ?? 'internal',
					}),
					headers: {
						'Content-Type': 'application/json',
					},
				},
			)
				.then((response) => {
					response.json().then((data) => {
						this.saving = false
						this.objectTypes.forEach((objectType) => {
							this[objectType].loading = false
						})
						this.settingsData = {
							...this.settingsData,
							attachment_register: data.attachment_register,
							attachment_schema: data.attachment_schema,
							attachment_source: data.attachment_source,
							catalog_register: data.catalog_register,
							catalog_schema: data.catalog_schema,
							catalog_source: data.catalog_source,
							listing_register: data.listing_register,
							listing_schema: data.listing_schema,
							listing_source: data.listing_source,
							organization_register: data.organization_register,
							organization_schema: data.organization_schema,
							organization_source: data.organization_source,
							publication_register: data.publication_register,
							publication_schema: data.publication_schema,
							publication_source: data.publication_source,
							publicationtype_register: data.publicationtype_register,
							publicationtype_schema: data.publicationtype_schema,
							publicationtype_source: data.publicationtype_source,
							theme_register: data.theme_register,
							theme_schema: data.theme_schema,
							theme_source: data.theme_source,
							page_register: data.page_register,
							page_schema: data.page_schema,
							page_source: data.page_source,
							menu_register: data.menu_register,
							menu_schema: data.menu_schema,
							menu_source: data.menu_source,
						}

					})
				})
				.catch((err) => {
					console.error(err)
					this.saving = false
					this.objectTypes.forEach((objectType) => {
						this[objectType].loading = false
					})
					return err
				})
		},

		resetConfig() {
			this.saving = true

			const settingsDataCopy = this.settingsData

			delete settingsDataCopy.objectTypes
			delete settingsDataCopy.openRegisters
			delete settingsDataCopy.availableRegisters

			fetch('/index.php/apps/opencatalogi/settings',
				{
					method: 'POST',
					body: JSON.stringify({
						...settingsDataCopy,
						attachment_source: 'internal',
						attachment_schema: '',
						attachment_register: '',
						catalog_source: 'internal',
						catalog_schema: '',
						catalog_register: '',
						listing_source: 'internal',
						listing_schema: '',
						listing_register: '',
						publicationtype_source: 'internal',
						publicationtype_schema: '',
						publicationtype_register: '',
						organization_source: 'internal',
						organization_schema: '',
						organization_register: '',
						publication_source: 'internal',
						publication_schema: '',
						publication_register: '',
						theme_source: 'internal',
						theme_schema: '',
						theme_register: '',
						page_source: 'internal',
						page_schema: '',
						page_register: '',
						menu_source: 'internal',
						menu_schema: '',
						menu_register: '',
					}),
					headers: {
						'Content-Type': 'application/json',
					},
				},
			)
				.then((response) => {
					response.json().then((data) => {
						this.saving = false

						this.fetchAll()

					})
				})
				.catch((err) => {
					console.error(err)
					this.saving = false
					return err
				})
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
	},

}
</script>
<style>
.selectionContainer {
	display: grid;
	grid-gap: 5px;
	grid-template-columns: 1fr;
}

.selectionContainer > * {
	margin-block-end: 10px;
}
</style>
