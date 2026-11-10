/** * ViewDirectoryModal.vue * Modal component for viewing directory listing details
* @category Modals * @package opencatalogi * @author Ruben Linde * @copyright 2024 *
@license EUPL-1.2 * @version 1.0.0 * @link
https://github.com/opencatalogi/opencatalogi */

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal
		v-if="navigationStore.modal === 'viewDirectory'"
		ref="modalRef"
		labelId="viewDirectoryModal"
		@close="closeModal">
		<div class="modal__content">
			<h2>
				{{
					listing?.name
					|| listing?.title
					|| t('opencatalogi', 'Directory Listing')
				}}
			</h2>

			<div v-if="listing" class="directoryDetails">
				<div class="detailSection">
					<h3>{{ t('opencatalogi', 'Basic information') }}</h3>
					<div class="detailGrid">
						<div class="detailItem">
							<strong>{{ t('opencatalogi', 'Name') }}:</strong>
							<span>{{ listing.name || listing.title || '-' }}</span>
						</div>
						<div v-if="listing.summary" class="detailItem">
							<strong>{{ t('opencatalogi', 'Summary') }}:</strong>
							<span>{{ listing.summary }}</span>
						</div>
						<div v-if="listing.description" class="detailItem">
							<strong>{{ t('opencatalogi', 'Description') }}:</strong>
							<span>{{ listing.description }}</span>
						</div>
						<div v-if="listing.organization" class="detailItem">
							<strong>{{ t('opencatalogi', 'Organization') }}:</strong>
							<span>{{
								listing.organization.title || listing.organization
							}}</span>
						</div>
					</div>
				</div>

				<div v-if="listing.publicationTypes?.length" class="detailSection">
					<h3>{{ t('opencatalogi', 'Publication types') }}</h3>
					<div class="publicationTypesList">
						<div
							v-for="publicationType in listing.publicationTypes"
							:key="publicationType.id || publicationType"
							class="publicationTypeItem">
							<strong>{{
								publicationType.title
								|| publicationType.name
								|| publicationType
							}}</strong>
							<span
								v-if="publicationType.description"
								class="description">
								{{ publicationType.description }}
							</span>
						</div>
					</div>
				</div>

				<div v-if="listing.metadata" class="detailSection">
					<h3>{{ t('opencatalogi', 'Metadata') }}</h3>
					<div class="metadataContainer">
						<pre>{{ JSON.stringify(listing.metadata, null, 2) }}</pre>
					</div>
				</div>
			</div>

			<div v-else class="emptyState">
				<p>{{ t('opencatalogi', 'No directory listing selected') }}</p>
			</div>

			<div class="modalActions">
				<NcButton @click="closeModal">
					{{ t('opencatalogi', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

/**
 * ViewDirectoryModal — inspect a directory entry.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'ViewDirectoryModal',
	components: {
		NcModal,
		NcButton,
	},

	computed: {
		/**
		 * Get the currently active listing from the store
		 *
		 * @return {object|null} The active listing object
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-directory-federation/tasks.md#task-2 */
		listing() {
			return objectStore.getActiveObject('listing')
		},
	},

	methods: {
		/**
		 * Close the modal and clear the active object
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-directory-federation/tasks.md#task-2 */
		closeModal() {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('listing')
		},
	},
}
</script>

<style scoped>
.modal__content {
	margin: var(--OC-margin-50);
	text-align: start;
	max-width: 80vw;
	max-height: 80vh;
	overflow-y: auto;
}

.directoryDetails {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-20);
	margin-top: var(--OC-margin-20);
}
</style>

<style scoped src="./detailSections.css"></style>

<style scoped>
.publicationTypesList {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-10);
}

.publicationTypeItem {
	padding: var(--OC-margin-10);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-5);
}

.publicationTypeItem .description {
	font-size: 0.9em;
	color: var(--color-text-lighter);
}
</style>

<style scoped src="./detailMetadata.css"></style>
