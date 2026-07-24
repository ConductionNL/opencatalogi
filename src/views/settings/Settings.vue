<template>
	<CnAdminSettingsShell
		app-id="opencatalogi"
		app-name="OpenCatalogi"
		doc-url="https://docs.opencatalogi.nl"
		:app-version="versionInfo.appVersion"
		:configured-version="versionInfo.configuredVersion"
		:is-up-to-date="versionInfo.versionsMatch"
		:show-update-button="versionInfo.needsUpdate"
		:show-reimport="false">
		<template #actions>
			<NcButton
				type="secondary"
				:disabled="importing"
				@click="manualImport(false)">
				<template #icon>
					<NcLoadingIcon v-if="importing" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ versionInfo.needsUpdate ? t('opencatalogi', 'Update configuration') : t('opencatalogi', 'Reimport configuration') }}
			</NcButton>

			<NcButton
				type="primary"
				:disabled="importing"
				@click="manualImport(true)">
				<template #icon>
					<NcLoadingIcon v-if="importing" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('opencatalogi', 'Force import') }}
			</NcButton>
		</template>

		<!-- Import Results -->
		<div v-if="importResult" class="import-result">
			<NcNoteCard
				v-if="importResult.success"
				type="success">
				{{ importResult.message }}
			</NcNoteCard>
			<NcNoteCard
				v-else
				type="error">
				{{ importResult.message }}
			</NcNoteCard>
		</div>

		<NcSettingsSection
			:name="t('opencatalogi', 'Data storage')"
			:description="t('opencatalogi', 'Configure where to store your publication data')">
			<div v-if="!loading">
				<!-- Warning if OpenRegister is not installed -->
				<NcNoteCard v-if="!settings.openRegisters" type="warning">
					{{ t('opencatalogi', 'Open Register is not installed. Please install it to use the Open Catalogi app with full functionality.') }}
				</NcNoteCard>

				<!-- Register Selection -->
				<div class="register-selection">
					<h3>{{ t('opencatalogi', 'Register') }}</h3>
					<p>{{ t('opencatalogi', 'Select the register to store all your publication data') }}</p>

					<NcSelect
						v-model="selectedRegister"
						:options="registerOptions"
						:input-label="t('opencatalogi', 'Register')"
						:disabled="loading || !settings.openRegisters"
						@change="handleRegisterChange" />
				</div>

				<!-- Warning if selected register has no schemas -->
				<NcNoteCard v-if="selectedRegister && !hasSchemas" type="warning">
					{{ t('opencatalogi', 'The selected register has no schemas. Please create schemas in this register or select a different register.') }}
				</NcNoteCard>

				<!-- Object Type Schema Configuration -->
				<div v-if="selectedRegister && hasSchemas" class="schema-configuration">
					<h3>{{ t('opencatalogi', 'Schema Configuration') }}</h3>
					<p>{{ t('opencatalogi', 'Select which schema to use for each object type') }}</p>

					<div v-for="objectType in settings.objectTypes" :key="objectType" class="object-type-section">
						<div class="object-type-header">
							<h4>{{ formatTitle(objectType) }}</h4>
						</div>

						<NcSelect
							v-model="configuration[objectType].schema"
							:options="computedSchemaOptions"
							:input-label="t('opencatalogi', 'Schema')"
							:disabled="loading" />
					</div>
				</div>

				<!-- Save Buttons -->
				<div class="button-container">
					<NcButton
						type="primary"
						:disabled="loading || saving || !selectedRegister || !hasSchemas"
						@click="saveAll">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<Save v-else :size="20" />
						</template>
						{{ t('opencatalogi', 'Save Configuration') }}
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('opencatalogi', 'Woo-index harvester readiness')"
			:description="t('opencatalogi', 'Verify that the KOOP Woo-harvester can actually reach and ingest this instance, and track its Woo-index registration status')">
			<div class="woo-readiness">
				<NcNoteCard v-if="wooReadinessError" type="error">
					{{ wooReadinessError }}
				</NcNoteCard>

				<div v-if="wooReadinessReport" class="woo-readiness-summary">
					<NcNoteCard :type="wooReadinessReport.verdict === 'ready' ? 'success' : 'warning'">
						{{ wooReadinessReport.verdict === 'ready'
							? t('opencatalogi', 'This instance is harvester-ready')
							: t('opencatalogi', 'This instance is not yet harvester-ready') }}
					</NcNoteCard>
					<p class="woo-readiness-meta">
						{{ t('opencatalogi', 'Last checked') }}: {{ formatCheckedAt(wooReadinessReport.checkedAt) }}
					</p>

					<ul class="woo-readiness-checks">
						<li v-for="check in wooReadinessReport.checks"
							:key="check.id"
							class="woo-readiness-check">
							<CheckCircle v-if="check.status === 'pass'" :size="20" fill-color="var(--color-success)" />
							<CloseCircle v-else-if="check.status === 'fail'" :size="20" fill-color="var(--color-error)" />
							<MinusCircle v-else :size="20" fill-color="var(--color-text-lighter)" />
							<div class="woo-readiness-check-body">
								<strong>{{ check.id }}</strong>
								<span v-if="check.reason" class="woo-readiness-check-reason">
									{{ remediationHint(check.reason) }}
								</span>
							</div>
						</li>
					</ul>
				</div>

				<p v-else-if="!wooReadinessRunning" class="woo-readiness-empty">
					{{ t('opencatalogi', 'No readiness check has been run yet.') }}
				</p>

				<div class="button-container">
					<NcButton
						type="primary"
						:disabled="wooReadinessRunning"
						@click="runWooReadinessCheck">
						<template #icon>
							<NcLoadingIcon v-if="wooReadinessRunning" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ t('opencatalogi', 'Run check') }}
					</NcButton>
				</div>

				<h3 class="woo-registration-heading">
					{{ t('opencatalogi', 'Woo-index registration status') }}
				</h3>
				<p class="option-description">
					{{ t('opencatalogi', 'Track whether this instance is registered with the national Woo-index / Register van Overheidsorganisaties') }}
				</p>

				<div class="woo-registration-fields">
					<NcSelect
						v-model="registration.status"
						:options="registrationStatusOptions"
						:input-label="t('opencatalogi', 'Registration status')"
						:disabled="savingRegistration" />

					<NcTextField
						:value="registration.registeredUrl"
						:label="t('opencatalogi', 'Registered URL')"
						:disabled="savingRegistration"
						@update:value="v => registration.registeredUrl = v" />

					<NcTextField
						:value="registration.registeredAt"
						:label="t('opencatalogi', 'Registered on (date)')"
						:disabled="savingRegistration"
						@update:value="v => registration.registeredAt = v" />
				</div>

				<div class="button-container">
					<NcButton
						type="secondary"
						:disabled="savingRegistration"
						@click="saveRegistration">
						<template #icon>
							<NcLoadingIcon v-if="savingRegistration" :size="20" />
							<Save v-else :size="20" />
						</template>
						{{ t('opencatalogi', 'Save registration status') }}
					</NcButton>
				</div>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('opencatalogi', 'Publishing Options')"
			:description="t('opencatalogi', 'Configure automatic publishing behavior and interface preferences')">
			<div v-if="!loading" class="publishing-options">
				<!-- Auto Publish Attachments -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="publishingOptions.autoPublishAttachments"
						:disabled="saving">
						{{ t('opencatalogi', 'Auto publish attachments') }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						{{ t('opencatalogi', 'When an object is published, automatically publish all its attachments as Nextcloud shares') }}
					</p>
				</div>

				<!-- Auto Publish Objects -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="publishingOptions.autoPublishObjects"
						:disabled="saving">
						{{ t('opencatalogi', 'Auto publish objects') }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						{{ t('opencatalogi', 'When an object matching a catalog schema is created, automatically apply public read access via RBAC rules') }}
					</p>
				</div>

				<!-- Use Old Style Publishing View -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="publishingOptions.useOldStylePublishingView"
						:disabled="saving">
						{{ t('opencatalogi', 'Use old style publishing view') }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						{{ t('opencatalogi', 'Use the legacy publishing interface instead of the new interface') }}
					</p>
				</div>

				<!-- Save Button for Publishing Options -->
				<div class="button-container">
					<NcButton
						type="primary"
						:disabled="loading || saving"
						@click="savePublishingOptions">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<Save v-else :size="20" />
						</template>
						{{ t('opencatalogi', 'Save Publishing Options') }}
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>
	</CnAdminSettingsShell>
</template>

<script>
import { defineComponent } from 'vue'
import {
	NcSettingsSection,
	NcNoteCard,
	NcSelect,
	NcTextField,
	NcButton,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import MinusCircle from 'vue-material-design-icons/MinusCircle.vue'

/**
 * @class Settings
 * @module Components
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Settings component for the Open Catalogi that allows users to configure
 * data storage options for different object types using Open Registers.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
export default defineComponent({
	name: 'Settings',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcSelect,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		CnAdminSettingsShell,
		Save,
		Refresh,
		CheckCircle,
		CloseCircle,
		MinusCircle,
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			loading: true,
			saving: false,
			loadingConfiguration: false,
			configurationResults: null,
			loadingVersionInfo: true,
			importing: false,
			settings: {
				objectTypes: [],
				openRegisters: false,
				availableRegisters: [],
				configuration: {},
			},
			selectedRegister: null,
			configuration: {},
			schemaOptions: [],
			publishingOptions: {
				autoPublishAttachments: false,
				autoPublishObjects: false,
				useOldStylePublishingView: false,
			},
			versionInfo: {
				appName: '',
				appVersion: '',
				configuredVersion: null,
				versionsMatch: false,
				needsUpdate: false,
			},
			importResult: null,
			wooReadinessReport: null,
			wooReadinessError: null,
			wooReadinessRunning: false,
			registration: {
				status: null,
				registeredUrl: '',
				registeredAt: '',
			},
			savingRegistration: false,
		}
	},

	computed: {
		/**
		 * Options for the Woo-index registration status selector.
		 *
		 * @return {Array<object>} Array of {label, value} options.
		 */
		/** @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md (Requirement: Woo-index registration status is tracked in configuration (WOO-HR-003)) */
		registrationStatusOptions() {
			return [
				{ label: this.t('opencatalogi', 'Not registered'), value: 'not_registered' },
				{ label: this.t('opencatalogi', 'Requested'), value: 'requested' },
				{ label: this.t('opencatalogi', 'Registered'), value: 'registered' },
			]
		},
		/**
		 * Generates options for register selection dropdown
		 *
		 * @return {Array<object>} Array of register options with label and value
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		registerOptions() {
			return this.settings.availableRegisters.map(register => ({
				label: register.title,
				value: register.id.toString(),
			}))
		},

		/**
		 * Determines if the selected register has schemas
		 *
		 * @return {boolean} True if the selected register has schemas, false otherwise
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		hasSchemas() {
			if (!this.selectedRegister) return false

			const register = this.settings.availableRegisters.find(
				r => r.id.toString() === this.selectedRegister.value,
			)

			// Check if register has schemas - accept both array of IDs or array of schema objects.
			if (!register || !Array.isArray(register.schemas)) {
				return false
			}

			// Accept either array of integers (schema IDs) or array of schema objects.
			return register.schemas.length > 0
				&& (register.schemas.some(schema => typeof schema === 'number' || (schema && typeof schema === 'object' && schema.id)))
		},
		/**
		 * Returns all available schema options (no filtering for reuse)
		 *
		 * @return {Array<object>} Array of available schema options
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		computedSchemaOptions() {
			// Don't filter out used schemas - allow reuse of schemas across object types
			return this.schemaOptions
		},
	},

	/**
	 * Lifecycle hook that loads settings when component is created
	 */
	/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
	async created() {
		await Promise.all([
			this.loadSettings(),
			this.loadVersionInfo(),
			this.loadWooReadiness(),
		])
	},

	methods: {
		/**
		 * Loads settings from the backend API and initializes the configuration
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		async loadSettings() {
			try {
				// Load main settings
				const response = await fetch('/index.php/apps/opencatalogi/api/settings')
				const data = await response.json()
				this.settings = data

				// Load publishing options
				const publishingResponse = await fetch('/index.php/apps/opencatalogi/api/settings/publishing')
				const publishingData = await publishingResponse.json()

				if (!publishingData.error) {
					this.publishingOptions = {
						autoPublishAttachments: publishingData.auto_publish_attachments,
						autoPublishObjects: publishingData.auto_publish_objects,
						useOldStylePublishingView: publishingData.use_old_style_publishing_view,
					}
				}

				// Initialize configuration object
				this.initializeConfiguration()

				// Find and select the Publication register if it exists
				this.autoSelectOpenCatalogiRegister()

				// Populate the Woo-index registration status editor from the same
				// settings payload (WOO-HR-003 keys are part of `configuration`).
				const registrationStatus = (data.configuration && data.configuration.woo_index_registration_status) || 'not_registered'
				this.registration = {
					status: this.registrationStatusOptions.find(option => option.value === registrationStatus) || this.registrationStatusOptions[0],
					registeredUrl: (data.configuration && data.configuration.woo_index_registration_url) || '',
					registeredAt: (data.configuration && data.configuration.woo_index_registration_at) || '',
				}

				this.loading = false
			} catch (error) {
				console.error('Failed to load settings:', error)
				this.loading = false
			}
		},

		/**
		 * Initializes the configuration object based on existing settings
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		initializeConfiguration() {
			// Create empty configuration for each object type
			this.settings.objectTypes.forEach(type => {
				const registerId = this.settings.configuration[`${type}_register`] || ''
				const schemaId = this.settings.configuration[`${type}_schema`] || ''

				this.configuration = {
					...this.configuration,
					[type]: {
						schema: null,
					},
				}

				// If we have existing configuration, use it to set the selected register
				if (registerId && !this.selectedRegister) {
					const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)
					if (register) {
						this.selectedRegister = {
							label: register.title,
							value: register.id.toString(),
						}
						this.updateSchemaOptions(register.id.toString())
					}
				}

				// If we have a schema configured, set it.
				if (schemaId && this.selectedRegister) {
					const register = this.settings.availableRegisters.find(
						r => r.id.toString() === this.selectedRegister.value,
					)
					if (register && Array.isArray(register.schemas)) {
						// Handle both schema IDs (numbers) and schema objects.
						let schema = null
						if (register.schemas.some(s => typeof s === 'number')) {
							// Schemas are just IDs, check if our schemaId is in the array.
							if (register.schemas.includes(parseInt(schemaId))) {
								schema = {
									id: schemaId,
									title: `Schema ${schemaId}`, // Fallback title.
								}
							}
						} else {
							// Schemas are objects, find the matching one.
							schema = register.schemas
								.filter(s => s && typeof s === 'object' && s.id && s.title)
								.find(s => s.id.toString() === schemaId)
						}

						if (schema) {
							this.configuration = {
								...this.configuration,
								[type]: {
									...this.configuration[type],
									schema: {
										label: schema.title,
										value: schema.id.toString(),
									},
								},
							}
						}
					}
				}
			})

		},

		/**
		 * Automatically selects the opencatalogi register if it exists
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		autoSelectOpenCatalogiRegister() {
			// Look for a register with "opencatalogi" in the name.
			const opencatalogiRegister = this.settings.availableRegisters.find(
				register => register.title.toLowerCase().includes('publication'),
			)

			if (opencatalogiRegister) {
				this.selectedRegister = {
					label: opencatalogiRegister.title,
					value: opencatalogiRegister.id.toString(),
				}
				this.updateSchemaOptions(opencatalogiRegister.id.toString())

				// Only try to auto-select schemas if the register has valid schemas.
				if (Array.isArray(opencatalogiRegister.schemas) && opencatalogiRegister.schemas.length > 0) {
					// Check if schemas are objects or just IDs.
					const hasSchemaObjects = opencatalogiRegister.schemas.some(schema => schema && typeof schema === 'object' && schema.id && schema.title)
					if (hasSchemaObjects) {
						this.autoSelectMatchingSchemas(opencatalogiRegister)
					}
				}
			} else if (this.settings.availableRegisters.length > 0 && !this.selectedRegister) {
				// If no Open Catalogi register but we have registers, select the first one.
				const firstRegister = this.settings.availableRegisters[0]
				this.selectedRegister = {
					label: firstRegister.title,
					value: firstRegister.id.toString(),
				}
				this.updateSchemaOptions(firstRegister.id.toString())

				// Only try to auto-select schemas if the register has valid schemas.
				if (Array.isArray(firstRegister.schemas) && firstRegister.schemas.length > 0) {
					// Check if schemas are objects or just IDs.
					const hasSchemaObjects = firstRegister.schemas.some(schema => schema && typeof schema === 'object' && schema.id && schema.title)
					if (hasSchemaObjects) {
						this.autoSelectMatchingSchemas(firstRegister)
					}
				}
			}
		},

		/**
		 * Auto-selects schemas that match object type names
		 *
		 * @param {object} register - The selected register object
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		autoSelectMatchingSchemas(register) {
			// Only proceed if register has schemas array
			if (!register || !Array.isArray(register.schemas)) {
				return
			}

			this.settings.objectTypes.forEach(type => {
				// Look for a schema with the same name as the object type
				// Filter out non-object schemas first
				const matchingSchema = register.schemas
					.filter(schema => schema && typeof schema === 'object' && schema.id && schema.title)
					.find(schema => schema.title.toLowerCase() === type.toLowerCase())

				if (matchingSchema) {
					this.configuration = {
						...this.configuration,
						[type]: {
							...this.configuration[type],
							schema: {
								label: matchingSchema.title,
								value: matchingSchema.id.toString(),
							},
						},
					}
				}
			})
		},

		/**
		 * Updates schema options based on the selected register
		 *
		 * @param {string} registerId - The ID of the selected register
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		updateSchemaOptions(registerId) {
			const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)
			if (register && Array.isArray(register.schemas)) {
				// Handle both schema IDs (numbers) and schema objects.
				if (register.schemas.some(s => typeof s === 'number')) {
					// Schemas are just IDs, create options with ID as both label and value.
					this.schemaOptions = register.schemas
						.filter(schemaId => typeof schemaId === 'number')
						.map(schemaId => ({
							label: `Schema ${schemaId}`,
							value: schemaId.toString(),
						}))
				} else {
					// Schemas are objects, filter out non-object schemas and only include valid schema objects.
					const validSchemas = register.schemas
						.filter(schema => schema && typeof schema === 'object' && schema.id && schema.title)
					this.schemaOptions = validSchemas.map(schema => ({
						label: schema.title,
						value: schema.id.toString(),
					}))
				}
			} else {
				this.schemaOptions = []
			}
		},

		/**
		 * Formats an object type string to title case
		 *
		 * @param {string} objectType - The object type to format
		 * @return {string} The formatted title
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		formatTitle(objectType) {
			return objectType.charAt(0).toUpperCase() + objectType.slice(1)
		},

		/**
		 * Handles register change event
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		handleRegisterChange() {
			if (this.selectedRegister) {
				// Update schema options for the new register
				this.updateSchemaOptions(this.selectedRegister.value)

				// Reset all schema selections
				this.settings.objectTypes.forEach(type => {
					this.configuration = {
						...this.configuration,
						[type]: {
							...this.configuration[type],
							schema: null,
						},
					}
				})

				// Auto-select matching schemas.
				const register = this.settings.availableRegisters.find(
					r => r.id.toString() === this.selectedRegister.value,
				)
				if (register && Array.isArray(register.schemas) && register.schemas.length > 0) {
					// Check if schemas are objects or just IDs.
					const hasSchemaObjects = register.schemas.some(schema => schema && typeof schema === 'object' && schema.id && schema.title)
					if (hasSchemaObjects) {
						this.autoSelectMatchingSchemas(register)
					}
				}
			}
		},

		/**
		 * Saves all configuration settings to the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		async saveAll() {
			if (!this.selectedRegister || !this.hasSchemas) {
				return
			}

			this.saving = true
			try {
				const configToSave = {}

				// Set all object types to use openregister as source
				Object.entries(this.configuration).forEach(([type, config]) => {
					// Always use openregister as source
					configToSave[`${type}_source`] = 'openregister'

					// Set the register ID for all object types
					configToSave[`${type}_register`] = this.selectedRegister.value

					// Set the schema ID if selected
					configToSave[`${type}_schema`] = config.schema ? config.schema.value : ''
				})

				// Send configuration to backend
				await fetch('/index.php/apps/opencatalogi/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(configToSave),
				})
			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Loads configuration from the backend API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		async loadConfiguration() {
			this.loadingConfiguration = true
			this.configurationResults = null

			try {
				const response = await fetch('/index.php/apps/opencatalogi/api/settings/load')
				const data = await response.json()

				if (data.error) {
					this.configurationResults = { error: data.error }
				} else {
					this.configurationResults = { success: true }
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.configurationResults = { error: 'Failed to load configuration: ' + error.message }
			} finally {
				this.loadingConfiguration = false
			}
		},

		/**
		 * Saves publishing options to the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		async savePublishingOptions() {
			this.saving = true
			try {
				const configToSave = {
					auto_publish_attachments: this.publishingOptions.autoPublishAttachments,
					auto_publish_objects: this.publishingOptions.autoPublishObjects,
					use_old_style_publishing_view: this.publishingOptions.useOldStylePublishingView,
				}

				// Send configuration to backend using the dedicated publishing options endpoint
				const response = await fetch('/index.php/apps/opencatalogi/api/settings/publishing', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(configToSave),
				})

				const result = await response.json()

				if (result.error) {
					console.error('Failed to save publishing options:', result.error)
				} else {
					// Update local state with the response from the backend
					this.publishingOptions = {
						autoPublishAttachments: result.auto_publish_attachments,
						autoPublishObjects: result.auto_publish_objects,
						useOldStylePublishingView: result.use_old_style_publishing_view,
					}
				}
			} catch (error) {
				console.error('Failed to save publishing options:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Loads version information from the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		async loadVersionInfo() {
			try {
				const response = await fetch('/index.php/apps/opencatalogi/api/settings/version')
				const data = await response.json()

				if (!data.error) {
					this.versionInfo = data
				} else {
					console.error('Failed to load version info:', data.error)
				}

				this.loadingVersionInfo = false
			} catch (error) {
				console.error('Failed to load version info:', error)
				this.loadingVersionInfo = false
			}
		},

		/**
		 * Manually trigger configuration import
		 *
		 * @param {boolean} force Whether to force the import
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-app-shell-settings/tasks.md#task-1 */
		async manualImport(force = false) {
			this.importing = true
			this.importResult = null

			try {
				const response = await fetch('/index.php/apps/opencatalogi/api/settings/import', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ force }),
				})

				const result = await response.json()
				this.importResult = result

				// If successful, update version info and reload settings
				if (result.success) {
					await Promise.all([
						this.loadVersionInfo(),
						this.loadSettings(),
					])
				}
			} catch (error) {
				console.error('Failed to perform manual import:', error)
				this.importResult = {
					success: false,
					message: 'Import failed: ' + error.message,
				}
			} finally {
				this.importing = false
			}
		},

		/**
		 * Loads the last persisted Woo-index harvester-readiness report.
		 *
		 * Read-only — performs no outbound checks itself (WOO-HR-002).
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md (Requirement: Readiness report is persisted and retrievable (WOO-HR-002)) */
		async loadWooReadiness() {
			try {
				const response = await fetch('/index.php/apps/opencatalogi/api/woo/readiness')
				const data = await response.json()
				this.wooReadinessReport = data.report || null
			} catch (error) {
				console.error('Failed to load Woo readiness report:', error)
			}
		},

		/**
		 * Triggers a fresh Woo-index harvester-readiness self-check run.
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md (Requirement: Harvester-readiness self-check validates the deployed public WOO surface (WOO-HR-001)) */
		async runWooReadinessCheck() {
			this.wooReadinessRunning = true
			this.wooReadinessError = null

			try {
				const response = await fetch('/index.php/apps/opencatalogi/api/woo/readiness/run', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const data = await response.json()

				if (!response.ok) {
					this.wooReadinessError = data.error === 'not-configured'
						? this.t('opencatalogi', 'No Woo-enabled catalog is configured yet — enable Woo sitemaps on a catalog first.')
						: (data.error || this.t('opencatalogi', 'Readiness check failed.'))
					return
				}

				this.wooReadinessReport = data
			} catch (error) {
				console.error('Failed to run Woo readiness check:', error)
				this.wooReadinessError = this.t('opencatalogi', 'Readiness check failed.')
			} finally {
				this.wooReadinessRunning = false
			}
		},

		/**
		 * Saves the Woo-index registration status editor via the existing settings save path.
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/woo-index-harvester-readiness/specs/woo-compliance/spec.md (Requirement: Woo-index registration status is tracked in configuration (WOO-HR-003)) */
		async saveRegistration() {
			this.savingRegistration = true

			try {
				await fetch('/index.php/apps/opencatalogi/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						woo_index_registration_status: this.registration.status ? this.registration.status.value : 'not_registered',
						woo_index_registration_url: this.registration.registeredUrl,
						woo_index_registration_at: this.registration.registeredAt,
					}),
				})
			} catch (error) {
				console.error('Failed to save Woo-index registration status:', error)
			} finally {
				this.savingRegistration = false
			}
		},

		/**
		 * Formats a readiness report's `checkedAt` ISO timestamp for display.
		 *
		 * @param {string} checkedAt ISO 8601 timestamp.
		 * @return {string} A locale-formatted date/time string.
		 */
		/** @spec exclude Display-formatting helper; no independent domain behavior. */
		formatCheckedAt(checkedAt) {
			if (!checkedAt) {
				return ''
			}
			try {
				return new Date(checkedAt).toLocaleString()
			} catch (error) {
				return checkedAt
			}
		},

		/**
		 * Maps a check's machine-readable failure/skip reason to a short remediation hint.
		 *
		 * @param {string} reason The machine-readable reason code.
		 * @return {string} A human-readable remediation hint.
		 */
		/** @spec exclude Display-copy lookup; no independent domain behavior. */
		remediationHint(reason) {
			const hints = {
				'http-404': this.t('opencatalogi', 'Not found (404) — check webserver routing/rewrites for this URL.'),
				'ssrf-blocked': this.t('opencatalogi', 'Blocked as an unsafe outbound target — check the configured public base URL.'),
				'network-error': this.t('opencatalogi', 'Could not connect — check that this instance is reachable from the public internet.'),
				'invalid-xml': this.t('opencatalogi', 'Response was not well-formed XML.'),
				'no-diwoo-elements': this.t('opencatalogi', 'No DIWOO metadata elements found — the category sitemap may be empty.'),
				'diwoo-xsd-invalid': this.t('opencatalogi', 'DIWOO metadata failed validation — see the DIWOO validation report for this catalog.'),
				'diwoo-validation-error': this.t('opencatalogi', 'Could not run DIWOO validation for this catalog/category.'),
				'missing-sitemap-reference': this.t('opencatalogi', 'robots.txt does not reference any Woo sitemap — check the robots.txt rewrite/proxy configuration.'),
				'sitemapindex-unreachable': this.t('opencatalogi', 'Skipped — the sitemapindex for this catalog was not reachable.'),
				'sitemapindex-invalid': this.t('opencatalogi', 'Skipped — the sitemapindex for this catalog was not well-formed.'),
				'no-sitemap-pages': this.t('opencatalogi', 'Skipped — the sitemapindex has no sitemap pages to sample.'),
				'no-publications-found': this.t('opencatalogi', 'Skipped — no publication URL was found to sample.'),
				'request-cap-reached': this.t('opencatalogi', 'Skipped — the per-run outbound request cap was reached.'),
				'not-registered': this.t('opencatalogi', 'Not yet registered with the Woo-index.'),
				'registration-pending': this.t('opencatalogi', 'Registration requested but not yet confirmed.'),
				'url-mismatch': this.t('opencatalogi', 'The registered URL does not match this instance\'s public base URL.'),
			}

			return hints[reason] || reason
		},
	},
})
</script>

<style scoped>
.load-configuration {
	margin-bottom: 2rem;
}

.configuration-results {
	margin-top: 1rem;
}

.register-selection {
	margin-bottom: 2rem;
	max-width: 400px;
}

.schema-configuration {
	margin-top: 2rem;
}

.object-type-section {
	margin-bottom: 1.5rem;
	display: flex;
	align-items: center;
	gap: 1rem;
}

.object-type-header {
	min-width: 150px;
}

.button-container {
	margin-top: 2rem;
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 2rem 0;
}

.publishing-options {
	max-width: 600px;
}

.option-section {
	margin-bottom: 1.5rem;
	padding: 1rem 0;
	border-bottom: 1px solid var(--color-border);
}

.option-section:last-child {
	border-bottom: none;
}

.option-description {
	margin-top: 0.5rem;
	color: var(--color-text-lighter);
	font-size: 0.9rem;
	line-height: 1.4;
}

.import-result {
	margin-top: 1rem;
}

.woo-readiness {
	max-width: 700px;
}

.woo-readiness-meta {
	margin: 0.5rem 0 1rem;
	color: var(--color-text-lighter);
	font-size: 0.9rem;
}

.woo-readiness-checks {
	list-style: none;
	margin: 0 0 1rem;
	padding: 0;
}

.woo-readiness-check {
	display: flex;
	align-items: flex-start;
	gap: 0.5rem;
	padding: 0.5rem 0;
	border-bottom: 1px solid var(--color-border);
}

.woo-readiness-check:last-child {
	border-bottom: none;
}

.woo-readiness-check-body {
	display: flex;
	flex-direction: column;
}

.woo-readiness-check-reason {
	color: var(--color-text-lighter);
	font-size: 0.85rem;
}

.woo-readiness-empty {
	color: var(--color-text-lighter);
	margin-bottom: 1rem;
}

.woo-registration-heading {
	margin-top: 2rem;
}

.woo-registration-fields {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	max-width: 400px;
	margin: 1rem 0;
}
</style>
