<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('opencatalogi', 'Publications') }}
				</h1>
				<p>{{ t('opencatalogi', 'Manage your publications and their status') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('opencatalogi', 'Showing {showing} of {total} publications', { showing: filteredPublications.length, total: currentPagination.total || filteredPublications.length }) }}
					</span>
					<span v-if="selectedPublications.length > 0" class="viewIndicator">
						({{ t('opencatalogi', '{count} selected', { count: selectedPublications.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-tooltip="'See publications as cards'"
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

					<NcActions
						:force-name="true"
						:inline="3"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="objectStore.clearActiveObject('publication'); navigationStore.setModal('objectModal')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Publication
						</NcActionButton>
						<NcActionButton
							close-after-click
							:disabled="catalogStore.isLoading"
							@click="catalogStore.fetchPublications">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
						<NcActionButton
							title="View documentation about publications"
							@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers/publicaties', '_blank')">
							<template #icon>
								<HelpCircleOutline :size="20" />
							</template>
							Help
						</NcActionButton>
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
					<NcButton type="primary" @click="objectStore.clearActiveObject('publication'); navigationStore.setModal('objectModal')">
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
									<ListBoxOutline v-if="publication['@self']?.published" :size="20" />
									<Pencil v-else-if="!publication['@self']?.published && !publication['@self']?.depublished" :size="20" />
									<AlertOutline v-else-if="publication['@self']?.depublished" :size="20" />
									{{ publication.title || publication.name || publication.titel || publication.naam || publication.id }}
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setModal('objectModal')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setDialog('copyPublication')">
										<template #icon>
											<ContentCopy :size="20" />
										</template>
										Copy
									</NcActionButton>
									<NcActionButton v-if="publication['@self'].published === null" close-after-click @click="publishPublication(publication, 'publish')">
										<template #icon>
											<Publish :size="20" />
										</template>
										Publish
									</NcActionButton>
									<NcActionButton v-if="publication['@self'].published" close-after-click @click="publishPublication(publication, 'depublish')">
										<template #icon>
											<PublishOff :size="20" />
										</template>
										Unpublish
									</NcActionButton>
									<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setModal('AddAttachment')">
										<template #icon>
											<FilePlusOutline :size="20" />
										</template>
										Add Attachment
									</NcActionButton>
									<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setDialog('deleteObject', { objectType: 'publication', dialogTitle: 'Publication' })">
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
										<th>{{ t('opencatalogi', 'Status') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>{{ t('opencatalogi', 'Status') }}</td>
										<td>{{ getPublicationStatus(publication) }}</td>
										<td>{{ getPublicationStatusLabel(publication) }}</td>
									</tr>
									<tr v-if="publication.summary">
										<td>{{ t('opencatalogi', 'Summary') }}</td>
										<td class="truncatedText">
											{{ publication.summary }}
										</td>
										<td>{{ 'Available' }}</td>
									</tr>
									<tr v-if="publication.description">
										<td>{{ t('opencatalogi', 'Description') }}</td>
										<td class="truncatedText">
											{{ publication.description }}
										</td>
										<td>{{ 'Available' }}</td>
									</tr>
									<tr v-if="publication.category">
										<td>{{ t('opencatalogi', 'Category') }}</td>
										<td>{{ publication.category }}</td>
										<td>{{ 'Set' }}</td>
									</tr>
									<tr v-if="publication.published">
										<td>{{ t('opencatalogi', 'Published') }}</td>
										<td>{{ new Date(publication.published).toLocaleDateString() }}</td>
										<td>{{ 'Published' }}</td>
									</tr>
									<tr v-if="publication.modified">
										<td>{{ t('opencatalogi', 'Modified') }}</td>
										<td>{{ publication.modified }}</td>
										<td>{{ 'Updated' }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>{{ t('opencatalogi', 'Title') }}</th>
									<th>{{ t('opencatalogi', 'Status') }}</th>
									<th>{{ t('opencatalogi', 'Category') }}</th>
									<th>{{ t('opencatalogi', 'Published') }}</th>
									<th>{{ t('opencatalogi', 'Modified') }}</th>
									<th class="tableColumnActions">
										{{ t('opencatalogi', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="publication in paginatedPublications"
									:key="publication.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedPublications.includes(publication.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedPublications.includes(publication.id)"
											@update:checked="(checked) => togglePublicationSelection(publication.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<div class="titleWithIcon">
												<ListBoxOutline v-if="publication['@self']?.published" :size="20" />
												<Pencil v-else-if="!publication['@self']?.published && !publication['@self']?.depublished" :size="20" />
												<AlertOutline v-else-if="publication['@self']?.depublished" :size="20" />
												<strong>{{ publication.title || publication.name || publication.titel || publication.naam || publication.id }}</strong>
											</div>
											<span v-if="publication.summary" class="textDescription textEllipsis">{{ publication.summary }}</span>
										</div>
									</td>
									<td>{{ getPublicationStatus(publication) }}</td>
									<td>{{ publication.category || '-' }}</td>
									<td>{{ publication.published ? new Date(publication.published).toLocaleDateString() : '-' }}</td>
									<td>{{ publication.modified || '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setModal('objectModal')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setDialog('copyPublication')">
												<template #icon>
													<ContentCopy :size="20" />
												</template>
												Copy
											</NcActionButton>
											<NcActionButton v-if="publication['@self'].published === null" close-after-click @click="publishPublication(publication, 'publish')">
												<template #icon>
													<Publish :size="20" />
												</template>
												Publish
											</NcActionButton>
											<NcActionButton v-if="publication['@self'].published" close-after-click @click="publishPublication(publication, 'depublish')">
												<template #icon>
													<PublishOff :size="20" />
												</template>
												Unpublish
											</NcActionButton>
											<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setModal('AddAttachment')">
												<template #icon>
													<FilePlusOutline :size="20" />
												</template>
												Add Attachment
											</NcActionButton>
											<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setDialog('deleteObject', { objectType: 'publication', dialogTitle: 'Publication' })">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Delete
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
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
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import ListBoxOutline from 'vue-material-design-icons/ListBoxOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import FilePlusOutline from 'vue-material-design-icons/FilePlusOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'PublicationTable',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		NcButton,
		ListBoxOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Refresh,
		Plus,
		ContentCopy,
		HelpCircleOutline,
		Publish,
		PublishOff,
		FilePlusOutline,
		AlertOutline,
		PaginationComponent,
	},
	data() {
		return {
			selectedPublications: [],
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
			return this.filteredPublications.length > 0 && this.filteredPublications.every(publication => this.selectedPublications.includes(publication.id))
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
	},
	mounted() {
		console.info('PublicationTable mounted, fetching publications...')
		catalogStore.fetchPublications()
	},
	methods: {
		setViewMode(mode) {
			console.info('Setting view mode to:', mode)
			this.viewMode = mode
		},
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedPublications = this.filteredPublications.map(publication => publication.id)
			} else {
				this.selectedPublications = []
			}
		},
		togglePublicationSelection(publicationId, checked) {
			if (checked) {
				this.selectedPublications.push(publicationId)
			} else {
				this.selectedPublications = this.selectedPublications.filter(id => id !== publicationId)
			}
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
		publishPublication(publication, mode) {
			objectStore.setActiveObject('publication', publication)
			fetch(`/index.php/apps/openregister/api/objects/${publication['@self'].register}/${publication['@self'].schema}/${publication.id}/${mode}`, {
				method: 'POST',
			}).then((response) => {
				catalogStore.fetchPublications()
				response.json().then((data) => {
					objectStore.setActiveObject('publication', { ...data, id: data.id || data['@self'].id })
				})
			})
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>

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

.viewContainer {
	padding: 20px;
}

.viewHeader {
	margin-bottom: 24px;
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

.cardGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
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
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 12px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
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
	width: 40px;
	text-align: center;
}

.tableColumnTitle {
	min-width: 200px;
}

.tableColumnActions {
	width: 60px;
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
</style> 