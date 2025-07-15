<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader publicationTableHeader">
				<h2 class="pageHeader">
					{{ t('opencatalogi', 'Publications') }}
				</h2>
				<p>{{ t('opencatalogi', 'Manage your publications and their status') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="filteredPublications.length" class="viewTotalCount">
						{{ t('opencatalogi', 'Showing {showing} of {total} publications', { showing: filteredPublications.length, total: currentPagination.total || filteredPublications.length }) }}
					</span>
					<span v-if="selectedPublications.length > 0" class="viewIndicator">
						({{ t('opencatalogi', '{count} selected', { count: selectedPublications.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<!-- Mass Actions Dropdown -->
					<NcActions
						:force-name="true"
						:disabled="selectedPublications.length === 0"
						:title="selectedPublications.length === 0 ? 'Select one or more publications to use mass actions' : `Mass actions (${selectedPublications.length} selected)`"
						:menu-name="`Mass Actions (${selectedPublications.length})`">
						<template #icon>
							<FormatListChecks :size="20" />
						</template>
						<NcActionButton
							:disabled="selectedPublications.length === 0"
							close-after-click
							@click="bulkDeletePublications">
							<template #icon>
								<Delete :size="20" />
							</template>
							Delete
						</NcActionButton>
						<NcActionButton
							:disabled="selectedPublications.length === 0"
							close-after-click
							@click="bulkPublishPublications">
							<template #icon>
								<Publish :size="20" />
							</template>
							Publish
						</NcActionButton>
						<NcActionButton
							:disabled="selectedPublications.length === 0"
							close-after-click
							@click="bulkDepublishPublications">
							<template #icon>
								<PublishOff :size="20" />
							</template>
							Depublish
						</NcActionButton>
					</NcActions>

					<!-- View Mode Switch -->
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-tooltip="'See publications as cards'"
							:class="viewMode === 'cards' ? 'active' : ''"
							:checked="viewMode === 'cards'"
							:button-variant="true"
							value="cards"
							name="publications_view_mode"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="() => setViewMode('cards')">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-tooltip="'See publications as a table'"
							:class="viewMode === 'table' ? 'active' : ''"
							:checked="viewMode === 'table'"
							:button-variant="true"
							value="table"
							name="publications_view_mode"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="() => setViewMode('table')">
							Table
						</NcCheckboxRadioSwitch>
					</div>

					<!-- Regular Actions -->
					<NcActions
						:force-name="true"
						:inline="2"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="addPublication">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Publication
						</NcActionButton>
						<NcActionButton
							close-after-click
							:disabled="catalogStore.isLoading"
							@click="refreshPublications">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
					</NcActions>

					<!-- Columns Actions -->
					<NcActions
						v-if="viewMode === 'table'"
						:force-name="true"
						:inline="1"
						menu-name="Columns">
						<template #icon>
							<FormatColumns :size="20" />
						</template>

						<!-- Metadata Section -->
						<NcActionCaption name="Metadata" />
						<NcActionCheckbox
							v-for="meta in metadataColumns"
							:key="`meta_${meta.id}`"
							:checked="objectStore.columnFilters[`meta_${meta.id}`]"
							@update:checked="(status) => objectStore.updateColumnFilter(`meta_${meta.id}`, status)">
							{{ meta.label }}
						</NcActionCheckbox>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="catalogStore.isLoading || !filteredPublications.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="catalogStore.isLoading" :size="64" />
					<ListBoxOutline v-else :size="64" />
				</template>
				<template v-if="!catalogStore.isLoading && !filteredPublications.length" #action>
					<NcButton type="primary" @click="addPublication">
						{{ t('opencatalogi', 'Add publication') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="publication in paginatedPublications" :key="publication.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="publication.summary">
									<PublishedIcon :object="publication" :size="20" />
									{{ publication['@self']?.name || publication.title || publication.name || publication.titel || publication.naam || publication.id }}
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="viewPublication(publication)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton
										v-if="shouldShowPublishAction(publication)"
										close-after-click
										@click="singlePublishPublication(publication)">
										<template #icon>
											<Publish :size="20" />
										</template>
										Publish
									</NcActionButton>
									<NcActionButton
										v-if="shouldShowDepublishAction(publication)"
										close-after-click
										@click="singleDepublishPublication(publication)">
										<template #icon>
											<PublishOff :size="20" />
										</template>
										Depublish
									</NcActionButton>
									<NcActionButton close-after-click @click="addAttachment(publication)">
										<template #icon>
											<FilePlusOutline :size="20" />
										</template>
										Add Attachment
									</NcActionButton>
									<NcActionButton close-after-click @click="singleDeletePublication(publication)">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Publication Statistics Table -->
							<table class="statisticsTable publicationStats">
								<thead>
									<tr>
										<th>{{ t('opencatalogi', 'Property') }}</th>
										<th>{{ t('opencatalogi', 'Value') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>{{ t('opencatalogi', 'Status') }}</td>
										<td>{{ getPublicationStatus(publication) }}</td>
									</tr>
									<tr v-if="publication.summary">
										<td>{{ t('opencatalogi', 'Summary') }}</td>
										<td class="truncatedText">
											{{ publication.summary }}
										</td>
									</tr>
									<tr v-if="publication.description">
										<td>{{ t('opencatalogi', 'Description') }}</td>
										<td class="truncatedText">
											{{ publication.description }}
										</td>
									</tr>
									<tr v-if="publication.category">
										<td>{{ t('opencatalogi', 'Category') }}</td>
										<td>{{ publication.category }}</td>
									</tr>
									<tr v-if="publication.published">
										<td>{{ t('opencatalogi', 'Published') }}</td>
										<td>{{ new Date(publication.published).toLocaleDateString() }}</td>
									</tr>
									<tr v-if="publication.modified">
										<td>{{ t('opencatalogi', 'Modified') }}</td>
										<td>{{ publication.modified }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<VueDraggable v-model="orderedEnabledColumns"
							target=".sort-target"
							animation="150"
							draggable="> *:not(.staticColumn)">
							<table class="viewTable">
								<thead>
									<tr class="viewTableRow sort-target">
										<th class="tableColumnCheckbox">
											<NcCheckboxRadioSwitch
												:checked="allSelected"
												:indeterminate="someSelected"
												@update:checked="toggleSelectAll" />
										</th>
										<th v-for="(column, index) in orderedEnabledColumns"
											:key="`header-${column.id || column.key || `col-${index}`}`"
											:class="getClassName(column.id)">
											<span class="stickyHeader columnTitle" :title="column.description">
												{{ column.label }}
											</span>
										</th>
										<th class="tableColumnActions">
											<!-- Empty header for actions column -->
										</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="publication in paginatedPublications"
										:key="publication['@self']?.id || publication.id"
										class="viewTableRow table-row-selectable"
										:class="{ 'table-row-selected': selectedPublications.includes(publication['@self']?.id || publication.id) }"
										@click="handleRowClick(publication['@self']?.id || publication.id, $event)">
										<td class="tableColumnCheckbox">
											<NcCheckboxRadioSwitch
												:checked="selectedPublications.includes(publication['@self']?.id || publication.id)"
												@update:checked="handleSelectPublication(publication['@self']?.id || publication.id)" />
										</td>
										<td v-for="(column, index) in orderedEnabledColumns"
											:key="`cell-${publication['@self']?.id || publication.id}-${column.id || column.key || `col-${index}`}`"
											:class="getClassName(column.id)">
											<span v-if="column.id === 'meta_files'" :class="`${column.id === 'meta_files' ? 'metaFilesContent' : ''}`">
												<NcCounterBubble :count="Array.isArray(publication['@self']?.files) ? publication['@self'].files.length : (publication['@self']?.files ? 1 : 0)" />
											</span>
											<span v-else-if="column.id === 'meta_created' || column.id === 'meta_updated'">
												{{ getValidISOstring(publication['@self']?.[column.key]) ? new Date(publication['@self'][column.key]).toLocaleString() : 'N/A' }}
											</span>
											<span v-else-if="column.id === 'meta_name'">
												<span class="titleWithIcon">
													<PublishedIcon :object="publication" :size="16" />
													<span class="truncatedName" :title="publication['@self']?.name || 'N/A'">
														{{ publication['@self']?.name || 'N/A' }}
													</span>
												</span>
											</span>
											<span v-else-if="column.id === 'meta_description'">
												<span>{{ publication['@self']?.description || 'N/A' }}</span>
											</span>
											<span v-else-if="column.id === 'meta_published'">
												{{ publication['@self']?.published ? getValidISOstring(publication['@self'].published) ? new Date(publication['@self'].published).toLocaleString() : publication['@self'].published : 'No' }}
											</span>
											<span v-else-if="column.id === 'meta_depublished'">
												{{ publication['@self']?.depublished ? getValidISOstring(publication['@self'].depublished) ? new Date(publication['@self'].depublished).toLocaleString() : publication['@self'].depublished : 'No' }}
											</span>
											<span v-else-if="column.id === 'meta_deleted'">
												{{ publication['@self']?.deleted ? getValidISOstring(publication['@self'].deleted) ? new Date(publication['@self'].deleted).toLocaleString() : publication['@self'].deleted : 'No' }}
											</span>
											<span v-else-if="column.id === 'meta_locked'">
												{{ publication['@self']?.locked ? 'Yes' : 'No' }}
											</span>
											<span v-else-if="column.id === 'meta_size'">
												{{ publication['@self']?.size ? `${publication['@self'].size} bytes` : 'N/A' }}
											</span>
											<span v-else>
												{{ publication['@self']?.[column.key] || 'N/A' }}
											</span>
										</td>
										<td class="tableColumnActions">
											<NcActions class="actionsButton">
												<NcActionButton close-after-click @click="viewPublication(publication)">
													<template #icon>
														<Pencil :size="20" />
													</template>
													Edit
												</NcActionButton>
												<NcActionButton
													v-if="shouldShowPublishAction(publication)"
													close-after-click
													@click="singlePublishPublication(publication)">
													<template #icon>
														<Publish :size="20" />
													</template>
													Publish
												</NcActionButton>
												<NcActionButton
													v-if="shouldShowDepublishAction(publication)"
													close-after-click
													@click="singleDepublishPublication(publication)">
													<template #icon>
														<PublishOff :size="20" />
													</template>
													Depublish
												</NcActionButton>
												<NcActionButton close-after-click @click="singleDeletePublication(publication)">
													<template #icon>
														<Delete :size="20" />
													</template>
													Delete
												</NcActionButton>
											</NcActions>
										</td>
									</tr>
								</tbody>
							</table>
						</VueDraggable>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				:current-page="currentPagination.page || 1"
				:total-pages="currentPagination.pages || Math.ceil(filteredPublications.length / (currentPagination.limit || 20))"
				:total-items="currentPagination.total || filteredPublications.length"
				:current-page-size="currentPagination.limit || 20"
				:min-items-to-show="0"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcActionCheckbox, NcActionCaption, NcCheckboxRadioSwitch, NcButton, NcCounterBubble } from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'
import getValidISOstring from '../../services/getValidISOstring.js'

import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import FilePlusOutline from 'vue-material-design-icons/FilePlusOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import FormatColumns from 'vue-material-design-icons/FormatColumns.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'
import PublishedIcon from '../../components/PublishedIcon.vue'

export default {
	name: 'PublicationTable',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcActionCheckbox,
		NcActionCaption,
		NcCheckboxRadioSwitch,
		NcButton,
		NcCounterBubble,
		VueDraggable,
		DotsHorizontal,
		TrashCanOutline,
		Refresh,
		Plus,
		Publish,
		PublishOff,
		FilePlusOutline,
		Pencil,
		Delete,
		FormatListChecks,
		FormatColumns,
		PaginationComponent,
		PublishedIcon,
	},
	data() {
		return {
			viewMode: 'table',
		}
	},
	computed: {
		filteredPublications() {
			return objectStore.getCollection('publication')?.results || []
		},
		currentPagination() {
			// Publications use catalogStore for pagination
			return catalogStore.publicationPagination || { page: 1, limit: 20, total: 0, pages: 0 }
		},
		paginatedPublications() {
			return this.filteredPublications
		},
		allSelected() {
			return this.filteredPublications.length > 0 && this.filteredPublications.every(publication =>
				this.selectedPublications.includes(publication['@self']?.id || publication.id),
			)
		},
		someSelected() {
			return this.selectedPublications.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (catalogStore.isLoading) {
				return t('opencatalogi', 'Loading publications...')
			} else if (!this.filteredPublications.length) {
				return t('opencatalogi', 'No publications found')
			}
			return ''
		},
		emptyContentDescription() {
			if (catalogStore.isLoading) {
				return t('opencatalogi', 'Please wait while we fetch your publications.')
			} else if (!this.filteredPublications.length) {
				return t('opencatalogi', 'No publications are available.')
			}
			return ''
		},
		metadataColumns() {
			// Get all available metadata columns from objectStore
			return Object.entries(objectStore.metadata).map(([key, meta]) => ({
				id: key, // Use the key directly, not prefixed with meta_
				...meta,
			}))
		},
		selectedPublications() {
			// Get selected publication IDs from the store
			return (objectStore.selectedObjects || []).map(obj =>
				obj.id || obj['@self']?.id,
			).filter(Boolean)
		},
		orderedEnabledColumns() {
			// Define the desired column order for publications
			const desiredOrder = ['meta_name', 'meta_published', 'meta_files', 'meta_updated']
			const enabledColumns = objectStore.enabledColumns

			// Sort columns based on desired order, putting unlisted columns at the end
			return enabledColumns.sort((a, b) => {
				const aIndex = desiredOrder.indexOf(a.id)
				const bIndex = desiredOrder.indexOf(b.id)

				if (aIndex === -1 && bIndex === -1) return 0 // Both not in desired order
				if (aIndex === -1) return 1 // a not in desired order, put at end
				if (bIndex === -1) return -1 // b not in desired order, put at end

				return aIndex - bIndex // Sort by desired order
			})
		},
	},

	mounted() {
		console.info('PublicationTable mounted, fetching publications...')
		catalogStore.fetchPublications()
		// Initialize column filters for publications
		objectStore.initializeColumnFilters()
		// Set default columns: title, published, files, updated
		objectStore.updateColumnFilter('meta_name', true)
		objectStore.updateColumnFilter('meta_published', true)
		objectStore.updateColumnFilter('meta_files', true)
		objectStore.updateColumnFilter('meta_updated', true)
		objectStore.updateColumnFilter('meta_depublished', false)
	},
	methods: {
		setViewMode(mode) {
			console.info('Setting view mode to:', mode)
			this.viewMode = mode
		},
		toggleSelectAll(checked) {
			if (checked) {
				// Select all - update store with full objects
				const selectedObjects = this.filteredPublications.map(pub => ({
					...pub,
					id: pub['@self']?.id || pub.id,
				}))
				objectStore.setSelectedObjects(selectedObjects)
			} else {
				// Deselect all
				objectStore.setSelectedObjects([])
			}
		},
		handleSelectPublication(publicationId) {
			const currentSelected = [...(objectStore.selectedObjects || [])]
			const existingIndex = currentSelected.findIndex(obj =>
				(obj.id || obj['@self']?.id) === publicationId,
			)

			if (existingIndex > -1) {
				// Remove from selection
				currentSelected.splice(existingIndex, 1)
			} else {
				// Add to selection - find the full object
				const publicationToAdd = this.filteredPublications.find(pub =>
					(pub['@self']?.id || pub.id) === publicationId,
				)
				if (publicationToAdd) {
					currentSelected.push({
						...publicationToAdd,
						id: publicationToAdd['@self']?.id || publicationToAdd.id,
					})
				}
			}

			objectStore.setSelectedObjects(currentSelected)
		},
		handleRowClick(id, event) {
			// Don't select if clicking on the checkbox, actions button, or inside actions menu
			if (event.target.closest('.tableColumnCheckbox')
				|| event.target.closest('.tableColumnActions')
				|| event.target.closest('.actionsButton')) {
				return
			}

			// Toggle selection on row click
			this.handleSelectPublication(id)
		},
		onPageChanged(page) {
			console.info('Page changed to:', page)
			catalogStore.fetchPublications({ page, limit: this.currentPagination.limit || 20 })
		},
		onPageSizeChanged(pageSize) {
			console.info('Page size changed to:', pageSize)
			catalogStore.fetchPublications({ page: 1, limit: pageSize })
		},
		getPublicationStatus(publication) {
			if (publication['@self']?.published) {
				return 'Published'
			} else if (publication['@self']?.depublished) {
				return 'Depublished'
			} else {
				return 'Draft'
			}
		},
		getPublicationStatusLabel(publication) {
			if (publication['@self']?.published) {
				return 'Live'
			} else if (publication['@self']?.depublished) {
				return 'Withdrawn'
			} else {
				return 'Not Published'
			}
		},
		addPublication() {
			// Clear any existing object and open the add publication modal
			objectStore.setActiveObject('publication', null)
			navigationStore.setModal('viewObject')
		},
		refreshPublications() {
			// Refresh the publication list
			catalogStore.fetchPublications()
			// Clear selection after refresh
			objectStore.setSelectedObjects([])
		},
		viewPublication(publication) {
			// Set the publication for viewing and open the view modal (now used for editing)
			objectStore.setActiveObject('publication', publication)
			navigationStore.setModal('viewObject')
		},
		editPublication(publication) {
			// Set the publication for editing and open the edit modal
			objectStore.setActiveObject('publication', publication)
			navigationStore.setModal('viewObject')
		},
		copyPublication(publication) {
			// Set the publication for copying and open the copy dialog
			objectStore.setActiveObject('publication', publication)
			navigationStore.setDialog('copyPublication')
		},
		singleDeletePublication(publication) {
			// Set the single publication as selected object (as full object, not just ID)
			const publicationObject = {
				...publication,
				id: publication['@self']?.id || publication.id,
			}
			objectStore.setSelectedObjects([publicationObject])

			// Open the mass delete dialog
			navigationStore.setDialog('massDeleteObject')
		},
		addAttachment(publication) {
			// Set the publication and open the add attachment modal
			objectStore.setActiveObject('publication', publication)
			navigationStore.setDialog('uploadFiles')
		},
		mergePublication(publication) {
			// Set the source publication for merging and open the merge modal
			objectStore.setActiveObject('publication', publication)
			navigationStore.setModal('mergeObject')
		},
		singlePublishPublication(publication) {
			// Set the single publication as selected object (as full object, not just ID)
			const publicationObject = {
				...publication,
				id: publication['@self']?.id || publication.id,
			}
			objectStore.setSelectedObjects([publicationObject])

			// Open the mass publish dialog
			navigationStore.setDialog('massPublishObjects')
		},
		singleDepublishPublication(publication) {
			// Set the single publication as selected object (as full object, not just ID)
			const publicationObject = {
				...publication,
				id: publication['@self']?.id || publication.id,
			}
			objectStore.setSelectedObjects([publicationObject])

			// Open the mass depublish dialog
			navigationStore.setDialog('massDepublishObjects')
		},
		bulkDeletePublications() {
			if (this.selectedPublications.length === 0) return

			// The selected objects are already in the store, just open the dialog
			navigationStore.setDialog('massDeleteObject')
		},
		bulkPublishPublications() {
			if (this.selectedPublications.length === 0) return

			// The selected objects are already in the store, just open the dialog
			navigationStore.setDialog('massPublishObjects')
		},
		bulkDepublishPublications() {
			if (this.selectedPublications.length === 0) return

			// The selected objects are already in the store, just open the dialog
			navigationStore.setDialog('massDepublishObjects')
		},
		bulkValidatePublications() {
			if (this.selectedPublications.length === 0) return

			// The selected objects are already in the store, just open the dialog
			navigationStore.setDialog('massValidateObjects')
		},
		shouldShowPublishAction(publication) {
			const published = publication['@self']?.published
			const depublished = publication['@self']?.depublished

			// Show publish if not published OR if depublished
			return !published || depublished
		},
		shouldShowDepublishAction(publication) {
			const published = publication['@self']?.published
			const depublished = publication['@self']?.depublished

			// Show depublish if published AND not depublished
			return published && !depublished
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
		getClassName(columnId) {
			switch (columnId) {
			case 'meta_files':
				return 'tableColumnMetaFiles'
			case 'meta_description':
				return 'tableColumnMetaDescription'
			case 'meta_name':
				return 'tableColumnMetaName'
			default:
				return ''
			}
		},
		getValidISOstring,
	},
}
</script>

<style>
.actionsButton > div > button {
    margin-top: 0px !important;
    margin-right: 0px !important;
    padding-right: 0px !important;
}
</style>

<style scoped>
.truncatedText {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}

.titleWithIcon {
	display: flex;
	align-items: center;
	gap: 8px;
}

.publicationStats {
	width: 100%;
	margin-top: 12px;
}

.publicationTableHeader {
	margin-inline-start: -20px;
}

.viewHeaderTitleIndented {
	margin: 0 0 8px 0;
	font-size: 24px;
	font-weight: 600;
}

.viewActionsBar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	gap: 16px;
}

.viewInfo {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
}

.viewActions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.viewModeSwitchContainer {
	display: flex;
}

.viewModeSwitchContainer :deep(.checkbox-radio-switch.active .checkbox-radio-switch__content),
.viewModeSwitchContainer :deep(.checkbox-radio-switch__content.active) {
    background-color: var(--color-primary-element) !important;
    color: var(--color-primary-text) !important;
    border-radius: var(--border-radius-large) !important;
	text-align: center;
}

.cardGrid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	margin-bottom: 20px;
}

.card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	background: var(--color-main-background);
}

.cardHeader {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 12px;
}

.cardHeader h2 {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	flex: 1;
	min-width: 0;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.statisticsTable th,
.statisticsTable td {
	padding: 6px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border-dark);
}

.statisticsTable th {
	background: var(--color-background-dark);
	font-weight: 600;
	font-size: 12px;
}

.viewTableContainer {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	table-layout: auto;
	min-width: 600px;
}

.viewTable th,
.viewTable td {
	padding: 12px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	width: auto;
	min-width: 100px;
	width: 100px;
}

/* Specific column width styling */
.tableColumnMetaName {
	min-width: 200px !important;
	width: auto !important;
}

.tableColumnMetaFiles {
	min-width: 80px !important;
	width: 80px !important;
	text-align: center !important;
}

.tableColumnMetaDescription {
	text-align: center !important;
}

.metaFilesContent {
	display: flex;
	justify-content: center;
}

.viewTable th {
	background: var(--color-background-dark);
	font-weight: 600;
	position: sticky;
	top: 0;
	z-index: 1;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.viewTableRowSelected {
	background: var(--color-primary-light);
}

.tableColumnCheckbox {
	width: 40px !important;
	min-width: 40px !important;
	max-width: 40px !important;
	text-align: center;
	padding: 8px !important;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch) {
	margin: 0;
	display: flex;
	align-items: center;
	justify-content: center;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch__content) {
	margin: 0;
}

.tableColumnTitle {
	min-width: 200px;
}

.tableColumnActions {
	width: 60px !important;
	min-width: 60px !important;
	max-width: 60px !important;
	text-align: center;
}

.tableColumnConstrained {
	max-width: 150px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.titleContent {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.textDescription {
	color: var(--color-text-lighter);
	font-size: 13px;
}

.textEllipsis {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.viewTotalCount {
	font-weight: 500;
}

.viewIndicator {
	color: var(--color-primary);
	font-weight: 500;
}

.columnTitle {
	font-weight: bold;
}

.stickyHeader {
	position: sticky;
	left: 0;
}

/* Row selection styling */
.table-row-selectable {
	cursor: pointer;
}

.table-row-selectable:hover {
	background-color: var(--color-background-hover);
}

.table-row-selected {
	background-color: var(--color-primary-light) !important;
}

.truncatedName {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	word-break: break-all;
	display: inline-block;
}
</style>
