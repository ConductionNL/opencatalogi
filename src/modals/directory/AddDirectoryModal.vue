/**
 * AddDirectoryModal.vue
 * Modal for synchronizing with external directories
 * @category Components
 * @package opencatalogi
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/opencatalogi
 */

<script setup>
import { ref } from 'vue'
import { navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'addDirectory'"
		ref="modalRef"
		class="addDirectoryModal"
		label-id="addDirectoryModal"
		@close="closeModal">
		<div class="modal__content">
			<h2>Directory synchroniseren</h2>
			<p class="description">
				Voer de URL van een OpenCatalogi directory in om te synchroniseren met hun catalogi en publicaties.
			</p>
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p><strong>Directory succesvol gesynchroniseerd</strong></p>
					<div v-if="syncResults" class="sync-report">
						<h4>Synchronisatie rapport</h4>
						<div class="sync-stats">
							<div class="stat-item">
								<span class="stat-label">Directory URL:</span>
								<span class="stat-value">{{ syncResults.directory_url }}</span>
							</div>
							<div class="stat-item">
								<span class="stat-label">Sync tijd:</span>
								<span class="stat-value">{{ formatDateTime(syncResults.sync_time) }}</span>
							</div>
							<div class="stat-item">
								<span class="stat-label">Totaal verwerkt:</span>
								<span class="stat-value">{{ syncResults.total_processed }}</span>
							</div>
							<div class="stat-item success">
								<span class="stat-label">Nieuwe listings:</span>
								<span class="stat-value">{{ syncResults.listings_created }}</span>
							</div>
							<div class="stat-item warning">
								<span class="stat-label">Bijgewerkte listings:</span>
								<span class="stat-value">{{ syncResults.listings_updated }}</span>
							</div>
							<div class="stat-item">
								<span class="stat-label">Ongewijzigde listings:</span>
								<span class="stat-value">{{ syncResults.listings_unchanged }}</span>
							</div>
							<div v-if="syncResults.listings_failed > 0" class="stat-item error">
								<span class="stat-label">Gefaalde listings:</span>
								<span class="stat-value">{{ syncResults.listings_failed }}</span>
							</div>
						</div>

						<!-- Show errors if any -->
						<div v-if="syncResults.errors && syncResults.errors.length > 0" class="sync-errors">
							<h5>Fouten tijdens synchronisatie:</h5>
							<ul>
															<li v-for="(syncError, index) in syncResults.errors" :key="index" class="error-item">
								{{ syncError }}
							</li>
							</ul>
						</div>

						<!-- Show detailed listing results if available -->
						<div v-if="showDetails && syncResults.listing_details && syncResults.listing_details.length > 0" class="listing-details">
							<h5>Gedetailleerde resultaten:</h5>
							<div class="listing-list">
								<div v-for="listing in syncResults.listing_details"
									:key="listing.listing_id"
									class="listing-item"
									:class="listing.success ? 'success' : 'error'">
									<span class="listing-title">{{ listing.listing_title || listing.listing_id }}</span>
									<span class="listing-action" :class="listing.action">{{ getActionLabel(listing.action) }}</span>
									<span v-if="listing.error" class="listing-error">{{ listing.error }}</span>
								</div>
							</div>
						</div>

						<NcButton v-if="syncResults.listing_details && syncResults.listing_details.length > 0"
							type="tertiary"
							class="toggle-details"
							@click="showDetails = !showDetails">
							{{ showDetails ? 'Verberg details' : 'Toon details' }}
						</NcButton>
					</div>
				</NcNoteCard>
				<NcNoteCard v-if="!success && error" type="error">
					<p><strong>Synchronisatie mislukt</strong></p>
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="success === null" class="form-group">
				<NcTextField
					v-model="directoryUrl"
					label="Directory URL"
					placeholder="https://directory.opencatalogi.nl/apps/opencatalogi/api/directory"
					:disabled="loading"
					:loading="loading"
					helper-text="De URL van de OpenCatalogi directory API endpoint" />
			</div>

			<span class="buttonContainer">
				<NcButton
					@click="closeModal">
					{{ success ? 'Sluiten' : 'Annuleer' }}
				</NcButton>
				<NcButton v-if="success === null"
					:disabled="loading || !directoryUrl"
					type="primary"
					@click="handleSync">
					<template #icon>
						<span>
							<NcLoadingIcon v-if="loading" :size="20" />
							<Sync v-if="!loading" :size="20" />
						</span>
					</template>
					Synchroniseren
				</NcButton>
			</span>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcTextField,
	NcNoteCard,
	NcLoadingIcon,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// icons
import Sync from 'vue-material-design-icons/Sync.vue'

/**
 * Loading state for the component
 * @type {import('vue').Ref<boolean>}
 */
const loading = ref(false)

/**
 * Success state for the component
 * @type {import('vue').Ref<boolean|null>}
 */
const success = ref(null)

/**
 * Error state for the component
 * @type {import('vue').Ref<string|null>}
 */
const error = ref(null)

/**
 * Directory URL to sync with
 * @type {import('vue').Ref<string>}
 */
const directoryUrl = ref('https://directory.opencatalogi.nl/apps/opencatalogi/api/directory')

/**
 * Sync results from the API
 * @type {import('vue').Ref<object|null>}
 */
const syncResults = ref(null)

/**
 * Handle directory synchronization
 * @return {Promise<void>}
 */
const handleSync = async () => {
	loading.value = true
	error.value = null
	try {
		const response = await axios.post(generateUrl('/apps/opencatalogi/api/directory'), {
			directory: directoryUrl.value,
		})

		success.value = true
		syncResults.value = response.data.data
	} catch (err) {
		console.error('Error synchronizing directory:', err)
		success.value = false
		error.value = err.response?.data?.error || err.response?.data?.message || err.message || 'Er is een fout opgetreden bij het synchroniseren'
	} finally {
		loading.value = false
	}
}

/**
 * Close the modal and reset state
 */
const closeModal = () => {
	navigationStore.setModal(false)
	// Reset state when closing
	success.value = null
	error.value = null
	syncResults.value = null
	directoryUrl.value = 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory'
}

export default {
	name: 'AddDirectoryModal',
	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		Sync,
	},
	data() {
		return {
			loading: false,
			success: null,
			error: null,
			directoryUrl: 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory',
			syncResults: null,
			showDetails: false,
		}
	},
	methods: {
		closeModal,
		handleSync,

		/**
		 * Format a date/time string for display
		 * @param {string|object} dateTime The date/time to format
		 * @return {string} Formatted date/time string
		 */
		formatDateTime(dateTime) {
			if (!dateTime) return 'Onbekend'

			try {
				const date = new Date(dateTime)
				if (isNaN(date.getTime())) return 'Onbekend'

				return date.toLocaleString('nl-NL', {
					year: 'numeric',
					month: '2-digit',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
				})
			} catch (e) {
				return 'Onbekend'
			}
		},

		/**
		 * Get a human-readable label for a listing action
		 * @param {string} action The action type
		 * @return {string} Human-readable label
		 */
		getActionLabel(action) {
			const labels = {
				created: 'Nieuw',
				updated: 'Bijgewerkt',
				unchanged: 'Ongewijzigd',
				failed: 'Gefaald',
				none: 'Geen actie',
			}
			return labels[action] || action
		},
	},
}
</script>

<style scoped>
.modal__content {
	padding: 20px;
}

.description {
	color: var(--color-text-lighter);
	margin-bottom: 20px;
}

.buttonContainer {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-top: 20px;
}

/* Sync report styles */
.sync-report {
	margin-top: 15px;
}

.sync-report h4, .sync-report h5 {
	margin: 15px 0 10px 0;
	color: var(--color-main-text);
}

.sync-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 10px;
	margin-bottom: 15px;
}

.stat-item {
	display: flex;
	justify-content: space-between;
	padding: 8px 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-border);
}

.stat-item.success {
	border-left-color: var(--color-success);
}

.stat-item.warning {
	border-left-color: var(--color-warning);
}

.stat-item.error {
	border-left-color: var(--color-error);
}

.stat-label {
	font-weight: 500;
	color: var(--color-text-lighter);
}

.stat-value {
	font-weight: 600;
	color: var(--color-main-text);
}

.sync-errors {
	margin: 15px 0;
	padding: 12px;
	background: var(--color-error-hover);
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-error);
}

.sync-errors h5 {
	color: var(--color-error);
	margin-bottom: 8px;
}

.sync-errors ul {
	margin: 0;
	padding-left: 20px;
}

.error-item {
	color: var(--color-error);
	margin-bottom: 4px;
}

.listing-details {
	margin: 15px 0;
}

.listing-list {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.listing-item {
	display: grid;
	grid-template-columns: 1fr auto auto;
	gap: 10px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	align-items: center;
}

.listing-item:last-child {
	border-bottom: none;
}

.listing-item.success {
	background: var(--color-success-hover);
}

.listing-item.error {
	background: var(--color-error-hover);
}

.listing-title {
	font-weight: 500;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.listing-action {
	padding: 4px 8px;
	border-radius: var(--border-radius-small);
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.listing-action.created {
	background: var(--color-success);
	color: white;
}

.listing-action.updated {
	background: var(--color-warning);
	color: white;
}

.listing-action.unchanged {
	background: var(--color-text-lighter);
	color: white;
}

.listing-action.failed {
	background: var(--color-error);
	color: white;
}

.listing-error {
	color: var(--color-error);
	font-size: 12px;
	font-style: italic;
}

.toggle-details {
	margin-top: 10px;
}
</style>
