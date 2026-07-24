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
			<h2>{{ t('opencatalogi', 'Synchronize Directory') }}</h2>
			<p class="description">
				{{ t('opencatalogi', 'Enter the URL of an OpenCatalogi directory to synchronize with their catalogs and publications.') }}
			</p>
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p><strong>{{ t('opencatalogi', 'Directory successfully synchronized') }}</strong></p>
					<div v-if="syncResults" class="sync-report">
						<h4>{{ t('opencatalogi', 'Synchronization report') }}</h4>
						<div class="sync-stats">
							<div class="stat-item">
								<span class="stat-label">{{ t('opencatalogi', 'Directory URL:') }}</span>
								<span class="stat-value">{{ syncResults.directory_url }}</span>
							</div>
							<div class="stat-item">
								<span class="stat-label">{{ t('opencatalogi', 'Sync time:') }}</span>
								<span class="stat-value">{{ formatDateTime(syncResults.sync_time) }}</span>
							</div>
							<div class="stat-item">
								<span class="stat-label">{{ t('opencatalogi', 'Total processed:') }}</span>
								<span class="stat-value">{{ syncResults.total_processed }}</span>
							</div>
							<div class="stat-item success">
								<span class="stat-label">{{ t('opencatalogi', 'New listings:') }}</span>
								<span class="stat-value">{{ syncResults.listings_created }}</span>
							</div>
							<div class="stat-item warning">
								<span class="stat-label">{{ t('opencatalogi', 'Updated listings:') }}</span>
								<span class="stat-value">{{ syncResults.listings_updated }}</span>
							</div>
							<div class="stat-item">
								<span class="stat-label">{{ t('opencatalogi', 'Unchanged listings:') }}</span>
								<span class="stat-value">{{ syncResults.listings_unchanged }}</span>
							</div>
							<div v-if="syncResults.listings_failed > 0" class="stat-item error">
								<span class="stat-label">{{ t('opencatalogi', 'Failed listings:') }}</span>
								<span class="stat-value">{{ syncResults.listings_failed }}</span>
							</div>
						</div>

						<!-- Show errors if any -->
						<div v-if="syncResults.errors && syncResults.errors.length > 0" class="sync-errors">
							<h5>{{ t('opencatalogi', 'Errors during synchronization:') }}</h5>
							<ul>
								<li v-for="(syncError, index) in syncResults.errors" :key="index" class="error-item">
									{{ syncError }}
								</li>
							</ul>
						</div>

						<!-- Show detailed listing results if available -->
						<div v-if="showDetails && syncResults.listing_details && syncResults.listing_details.length > 0" class="listing-details">
							<h5>{{ t('opencatalogi', 'Detailed results:') }}</h5>
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
							{{ showDetails ? t('opencatalogi', 'Hide details') : t('opencatalogi', 'Show details') }}
						</NcButton>
					</div>
				</NcNoteCard>
				<NcNoteCard v-if="!success && error" type="error">
					<p><strong>{{ t('opencatalogi', 'Synchronization failed') }}</strong></p>
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="success === null" class="form-group">
				<NcTextField
					v-model="directoryUrl"
					:label="t('opencatalogi', 'Directory URL')"
					:placeholder="defaultDirectoryUrl"
					:disabled="loading"
					:loading="loading"
					:helper-text="t('opencatalogi', 'The URL of the OpenCatalogi directory API endpoint')" />
			</div>

			<span class="buttonContainer">
				<NcButton
					@click="closeModal">
					{{ success ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
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
					{{ t('opencatalogi', 'Sync') }}
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
import { loadState } from '@nextcloud/initial-state'

// icons
import Sync from 'vue-material-design-icons/Sync.vue'

// Loaded from initial state (`default_directory_url` override, else the
// server-side Application::DEFAULT_DIRECTORY_URL). Don't hardcode the literal.
const DEFAULT_DIRECTORY_URL = loadState(
	'opencatalogi',
	'default_directory_url',
	'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory',
)

const loading = ref(false)
const success = ref(null)
const error = ref(null)
const directoryUrl = ref(DEFAULT_DIRECTORY_URL)
const syncResults = ref(null)

const handleSync = async () => {
	loading.value = true
	error.value = null
	try {
		// Admin-only `/api/listings/add` (WOO-513) — same syncDirectory() as the
		// public `/api/directory` gossip endpoint, but requires an authed user.
		const response = await axios.post(generateUrl('/apps/opencatalogi/api/listings/add'), {
			url: directoryUrl.value,
		})

		success.value = true
		// `/api/listings/add` returns the sync report bare; the `?? response.data`
		// fallback is defensive dead-code kept for future shape regressions.
		syncResults.value = response.data.data ?? response.data
	} catch (err) {
		console.error('Error synchronizing directory:', err)
		success.value = false
		error.value = err.response?.data?.error || err.response?.data?.message || err.message || 'Er is een fout opgetreden bij het synchroniseren'
	} finally {
		loading.value = false
	}
}

const closeModal = () => {
	navigationStore.setModal(false)
	success.value = null
	error.value = null
	syncResults.value = null
	directoryUrl.value = DEFAULT_DIRECTORY_URL
}

/**
 * AddDirectoryModal — register an external directory by POSTing its URL.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
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
			directoryUrl: DEFAULT_DIRECTORY_URL,
			defaultDirectoryUrl: DEFAULT_DIRECTORY_URL,
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
		/** @spec openspec/changes/retrofit-2026-05-26-directory-federation/tasks.md#task-2 */
		formatDateTime(dateTime) {
			if (!dateTime) return 'Unknown'

			try {
				const date = new Date(dateTime)
				if (isNaN(date.getTime())) return 'Unknown'

				return date.toLocaleString('nl-NL', {
					year: 'numeric',
					month: '2-digit',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
				})
			} catch (e) {
				return 'Unknown'
			}
		},

		/**
		 * Get a human-readable label for a listing action
		 * @param {string} action The action type
		 * @return {string} Human-readable label
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-directory-federation/tasks.md#task-2 */
		getActionLabel(action) {
			const labels = {
				created: 'New',
				updated: 'Updated',
				unchanged: 'Unchanged',
				failed: 'Failed',
				none: 'No action',
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
