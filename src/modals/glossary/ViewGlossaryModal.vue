/** * ViewGlossaryModal.vue * Modal component for viewing glossary term details *
@category Modals * @package opencatalogi * @author Ruben Linde * @copyright 2024 *
@license EUPL-1.2 * @version 1.0.0 * @link
https://github.com/opencatalogi/opencatalogi */

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal
		v-if="navigationStore.modal === 'viewGlossary'"
		ref="modalRef"
		:name="term?.title || t('opencatalogi', 'Glossary Term')"
		labelId="viewGlossaryModal"
		@close="closeModal">
		<div class="modal__content">
			<div v-if="term" class="glossaryDetails">
				<div class="detailSection">
					<h3>{{ t('opencatalogi', 'Basic information') }}</h3>
					<div class="detailGrid">
						<div class="detailItem">
							<strong>{{ t('opencatalogi', 'Title') }}:</strong>
							<span>{{ term.title || '-' }}</span>
						</div>
						<div class="detailItem">
							<strong>{{ t('opencatalogi', 'Status') }}:</strong>
							<span>{{
								term.published
									? t('opencatalogi', 'Published')
									: t('opencatalogi', 'Draft')
							}}</span>
						</div>
						<div v-if="term.summary" class="detailItem">
							<strong>{{ t('opencatalogi', 'Summary') }}:</strong>
							<span>{{ term.summary }}</span>
						</div>
						<div v-if="term.description" class="detailItem">
							<strong>{{ t('opencatalogi', 'Description') }}:</strong>
							<span>{{ term.description }}</span>
						</div>
						<div v-if="term.externalLink" class="detailItem">
							<strong
								>{{ t('opencatalogi', 'External link') }}:</strong
							>
							<span>
								<a
									:href="term.externalLink"
									target="_blank"
									rel="noopener noreferrer">
									{{ term.externalLink }}
								</a>
							</span>
						</div>
					</div>
				</div>

				<div v-if="term.keywords?.length" class="detailSection">
					<h3>{{ t('opencatalogi', 'Keywords') }}</h3>
					<div class="keywordsList">
						<span
							v-for="keyword in term.keywords"
							:key="keyword"
							class="keywordTag">
							{{ keyword }}
						</span>
					</div>
				</div>

				<div v-if="term.relatedTerms?.length" class="detailSection">
					<h3>{{ t('opencatalogi', 'Related terms') }}</h3>
					<div class="relatedTermsList">
						<NcButton
							v-for="relatedTerm in term.relatedTerms"
							:key="relatedTerm.id"
							variant="secondary"
							@click="selectTerm(relatedTerm)">
							{{ relatedTerm.title }}
						</NcButton>
					</div>
				</div>

				<div v-if="term.metadata" class="detailSection">
					<h3>{{ t('opencatalogi', 'Metadata') }}</h3>
					<div class="metadataContainer">
						<pre>{{ JSON.stringify(term.metadata, null, 2) }}</pre>
					</div>
				</div>
			</div>

			<div v-else class="emptyState">
				<p>{{ t('opencatalogi', 'No glossary term selected') }}</p>
			</div>

			<div class="modalActions">
				<NcButton variant="secondary" @click="openEditModal">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcButton>
				<NcButton @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ t('opencatalogi', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

/**
 * ViewGlossaryModal — read-only view of a glossary term.
 *
 * @spec openspec/specs/content-management/spec.md
 */
export default {
	name: 'ViewGlossaryModal',
	components: {
		NcModal,
		NcButton,
		Pencil,
		Cancel,
	},

	computed: {
		/**
		 * Get the currently active glossary term from the store
		 *
		 * @return {object|null} The active glossary term object
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-theme-glossary/tasks.md#task-2 */
		term() {
			return objectStore.getActiveObject('glossary')
		},
	},

	methods: {
		/**
		 * Close the modal and clear the active object
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-theme-glossary/tasks.md#task-2 */
		closeModal() {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('glossary')
		},

		/**
		 * Open the edit modal for the current term
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-theme-glossary/tasks.md#task-2 */
		openEditModal() {
			navigationStore.setModal('glossary')
		},

		/**
		 * Select a related term and view its details
		 *
		 * @param {object} term - The term to select
		 * @return {void}
		 */
		/**
		 * @param term
		 * @spec openspec/changes/retrofit-2026-05-26-theme-glossary/tasks.md#task-2
		 */
		selectTerm(term) {
			objectStore.setActiveObject('glossary', term)
		},
	},
}
</script>

<style scoped src="../../css/detail-modal.css"></style>

<style scoped>
.modal__content {
	text-align: start;
	max-width: 80vw;
	max-height: 80vh;
	overflow-y: auto;
}

.glossaryDetails {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-20);
	margin-top: var(--OC-margin-20);
}

.keywordsList {
	display: flex;
	flex-wrap: wrap;
	gap: var(--OC-margin-10);
}

.keywordTag {
	background-color: var(--color-primary-light);
	color: var(--color-primary-text);
	padding: var(--OC-margin-5) var(--OC-margin-10);
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
	font-weight: 500;
}

.relatedTermsList {
	display: flex;
	flex-wrap: wrap;
	gap: var(--OC-margin-10);
}
</style>
