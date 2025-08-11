<template>
	<div>
		<NcSettingsSection
			name="Open Catalogi"
			description="A central place for managing your Catalogi and publications"
			doc-url="https://docs.opencatalogi.nl" />

		<NcSettingsSection
			name="Version Information"
			description="Current application and configuration versions">
			<div v-if="!loadingVersionInfo" class="version-info">
				<div class="version-details">
					<div class="version-item">
						<strong>Application:</strong> {{ versionInfo.appName }} v{{ versionInfo.appVersion }}
					</div>
					<div class="version-item">
						<strong>Configured Version:</strong>
						<span v-if="versionInfo.configuredVersion">{{ versionInfo.configuredVersion }}</span>
						<span v-else class="no-version">Not configured</span>
					</div>
					<div class="version-item">
						<strong>Status:</strong>
						<span v-if="versionInfo.versionsMatch" class="status-ok">✓ Up to date</span>
						<span v-else-if="versionInfo.needsUpdate" class="status-warning">⚠ Update needed</span>
						<span v-else class="status-error">✗ Version mismatch</span>
					</div>
				</div>

				<!-- Manual Import Section -->
				<div class="manual-import">
					<div class="import-actions">
						<NcButton
							type="secondary"
							:disabled="importing"
							@click="manualImport(false)">
							<template #icon>
								<NcLoadingIcon v-if="importing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							{{ versionInfo.needsUpdate ? 'Update Configuration' : 'Reimport Configuration' }}
						</NcButton>

						<NcButton
							v-if="!versionInfo.versionsMatch"
							type="primary"
							:disabled="importing"
							@click="manualImport(true)">
							<template #icon>
								<NcLoadingIcon v-if="importing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Force Import
						</NcButton>
					</div>

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
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection
			name="Data storage"
			description="Configure where to store your publication data">
			<div v-if="!loading">
				<!-- Warning if OpenRegister is not installed -->
				<NcNoteCard v-if="!settings.openRegisters" type="warning">
					Open Register is not installed. Please install it to use the Open Catalogi app with full functionality.
				</NcNoteCard>

				<!-- Register Selection -->
				<div class="register-selection">
					<h3>Register</h3>
					<p>Select the register to store all your publicatie data</p>

					<NcSelect
						v-model="selectedRegister"
						:options="registerOptions"
						input-label="Register"
						:disabled="loading || !settings.openRegisters"
						@change="handleRegisterChange" />
				</div>

				<!-- Warning if selected register has no schemas -->
				<NcNoteCard v-if="selectedRegister && !hasSchemas" type="warning">
					The selected register has no schemas. Please create schemas in this register or select a different register.
				</NcNoteCard>

				<!-- Object Type Schema Configuration -->
				<div v-if="selectedRegister && hasSchemas" class="schema-configuration">
					<h3>Schema Configuration</h3>
					<p>Select which schema to use for each object type</p>

					<div v-for="objectType in settings.objectTypes" :key="objectType" class="object-type-section">
						<div class="object-type-header">
							<h4>{{ formatTitle(objectType) }}</h4>
						</div>

						<NcSelect
							v-model="configuration[objectType].schema"
							:options="computedSchemaOptions"
							input-label="Schema"
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
						Save Configuration
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
			name="Publishing Options"
			description="Configure automatic publishing behavior and interface preferences">
			<div v-if="!loading" class="publishing-options">
				<!-- Auto Publish Attachments -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="publishingOptions.autoPublishAttachments"
						:disabled="saving">
						Auto publish attachments
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						When an object that has published not null automatically publish all publications
					</p>
				</div>

				<!-- Auto Publish Objects -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="publishingOptions.autoPublishObjects"
						:disabled="saving">
						Auto publish objects
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						When an object that has a schema and register matching a catalog is created automatically set it to published
					</p>
				</div>

				<!-- Use Old Style Publishing View -->
				<div class="option-section">
					<NcCheckboxRadioSwitch
						:checked.sync="publishingOptions.useOldStylePublishingView"
						:disabled="saving">
						Use old style publishing view
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						Use the legacy publishing interface instead of the new interface
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
						Save Publishing Options
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>
	</div>
</template>

<script>
import { defineComponent } from 'vue'
import {
	NcSettingsSection,
	NcNoteCard,
	NcSelect,
	NcButton,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

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
 */
export default defineComponent({
	name: 'Settings',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Save,
		Refresh,
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
		}
	},

	computed: {
		/**
		 * Generates options for register selection dropdown
		 *
		 * @return {Array<object>} Array of register options with label and value
		 */
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
		hasSchemas() {
			if (!this.selectedRegister) return false

			const register = this.settings.availableRegisters.find(
				r => r.id.toString() === this.selectedRegister.value,
			)

			// Check if register has valid schema objects (not just any array items)
			return register && Array.isArray(register.schemas)
				&& register.schemas.some(schema => schema && typeof schema === 'object' && schema.id && schema.title)
		},
		/**
		 * Returns all available schema options (no filtering for reuse)
		 *
		 * @return {Array<object>} Array of available schema options
		 */
		computedSchemaOptions() {
			// Don't filter out used schemas - allow reuse of schemas across object types
			return this.schemaOptions
		},
	},

	/**
	 * Lifecycle hook that loads settings when component is created
	 */
	async created() {
		await Promise.all([
			this.loadSettings(),
			this.loadVersionInfo(),
		])
	},

	methods: {
		/**
		 * Loads settings from the backend API and initializes the configuration
		 *
		 * @async
		 * @return {Promise<void>}
		 */
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

				this.loading = false
			} catch (error) {
				console.error('Failed to load settings:', error)
				this.loading = false
			}
		},

		/**
		 * Initializes the configuration object based on existing settings
		 */
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

				// If we have a schema configured, set it
				if (schemaId && this.selectedRegister) {
					const register = this.settings.availableRegisters.find(
						r => r.id.toString() === this.selectedRegister.value,
					)
					if (register && Array.isArray(register.schemas)) {
						// Filter out non-object schemas and find the matching one
						const schema = register.schemas
							.filter(s => s && typeof s === 'object' && s.id && s.title)
							.find(s => s.id.toString() === schemaId)
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
		autoSelectOpenCatalogiRegister() {
			// Look for a register with "opencatalogi" in the name
			const opencatalogiRegister = this.settings.availableRegisters.find(
				register => register.title.toLowerCase().includes('publication'),
			)

			if (opencatalogiRegister) {
				this.selectedRegister = {
					label: opencatalogiRegister.title,
					value: opencatalogiRegister.id.toString(),
				}
				this.updateSchemaOptions(opencatalogiRegister.id.toString())

				// Only try to auto-select schemas if the register has valid schemas
				if (Array.isArray(opencatalogiRegister.schemas)
					&& opencatalogiRegister.schemas.some(schema => schema && typeof schema === 'object' && schema.id && schema.title)) {
					this.autoSelectMatchingSchemas(opencatalogiRegister)
				}
			} else if (this.settings.availableRegisters.length > 0 && !this.selectedRegister) {
				// If no Open Catalogi register but we have registers, select the first one
				const firstRegister = this.settings.availableRegisters[0]
				this.selectedRegister = {
					label: firstRegister.title,
					value: firstRegister.id.toString(),
				}
				this.updateSchemaOptions(firstRegister.id.toString())

				// Only try to auto-select schemas if the register has valid schemas
				if (Array.isArray(firstRegister.schemas)
					&& firstRegister.schemas.some(schema => schema && typeof schema === 'object' && schema.id && schema.title)) {
					this.autoSelectMatchingSchemas(firstRegister)
				}
			}
		},

		/**
		 * Auto-selects schemas that match object type names
		 *
		 * @param {object} register - The selected register object
		 */
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
		updateSchemaOptions(registerId) {
			const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)
			if (register && Array.isArray(register.schemas)) {
				// Filter out non-object schemas and only include valid schema objects
				const validSchemas = register.schemas
					.filter(schema => schema && typeof schema === 'object' && schema.id && schema.title)
				this.schemaOptions = validSchemas.map(schema => ({
					label: schema.title,
					value: schema.id.toString(),
				}))
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
		formatTitle(objectType) {
			return objectType.charAt(0).toUpperCase() + objectType.slice(1)
		},

		/**
		 * Handles register change event
		 */
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

				// Auto-select matching schemas
				const register = this.settings.availableRegisters.find(
					r => r.id.toString() === this.selectedRegister.value,
				)
				if (register && Array.isArray(register.schemas)
					&& register.schemas.some(schema => schema && typeof schema === 'object' && schema.id && schema.title)) {
					this.autoSelectMatchingSchemas(register)
				}
			}
		},

		/**
		 * Saves all configuration settings to the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
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

.version-info {
	max-width: 600px;
}

.version-details {
	margin-bottom: 2rem;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.version-item {
	margin-bottom: 0.5rem;
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.version-item:last-child {
	margin-bottom: 0;
}

.no-version {
	color: var(--color-text-lighter);
	font-style: italic;
}

.status-ok {
	color: var(--color-success);
	font-weight: bold;
}

.status-warning {
	color: var(--color-warning);
	font-weight: bold;
}

.status-error {
	color: var(--color-error);
	font-weight: bold;
}

.manual-import {
	margin-top: 1.5rem;
}

.import-actions {
	display: flex;
	gap: 1rem;
	margin-bottom: 1rem;
}

.import-result {
	margin-top: 1rem;
}
</style>
