<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { navigationStore, objectStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div class="head">
			<h1 class="h1">
				{{ objectStore.getActiveObject('publication')?.title }}
			</h1>

			<NcActions :disabled="objectStore.isLoading('publication')"
				:primary="true"
				:menu-name="objectStore.isLoading('publication') ? t('opencatalogi', 'Loading...') : t('opencatalogi', 'Actions')"
				:inline="1"
				:title="t('opencatalogi', 'Actions you can perform on this publication')">
				<template #icon>
					<span>
						<NcLoadingIcon v-if="objectStore.isLoading('publication')" :size="20" appearance="dark" />
						<DotsHorizontal v-if="!objectStore.isLoading('publication')" :size="20" />
					</span>
				</template>
				<NcActionButton close-after-click
					:title="t('opencatalogi', 'View the documentation about publications')"
					@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers/publicaties', '_blank')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					{{ t('opencatalogi', 'Help') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="navigationStore.setModal('objectModal')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="navigationStore.setDialog('copyPublication')">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton v-if="objectStore.getActiveObject('publication')['@self']?.published === null"
					close-after-click
					@click="publishPublication('publish')">
					<template #icon>
						<Publish :size="20" />
					</template>
					{{ t('opencatalogi', 'Publish') }}
				</NcActionButton>
				<NcActionButton v-if="objectStore.getActiveObject('publication')['@self']?.published"
					close-after-click
					@click="publishPublication('depublish')">
					<template #icon>
						<PublishOff :size="20" />
					</template>
					{{ t('opencatalogi', 'Depublish') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="navigationStore.setDialog('downloadPublication')">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('opencatalogi', 'Download') }}
				</NcActionButton>
				<NcActionButton close-after-click disabled>
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					{{ t('opencatalogi', 'Add attachment') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="navigationStore.setDialog('deleteObject', { objectType: 'publication', dialogTitle: t('opencatalogi', 'Publication') })">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('opencatalogi', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</div>
		<div class="container">
			<div class="detailGrid">
				<div v-if="publication.reference">
					<b>{{ t('opencatalogi', 'Reference') }}:</b>
					<span>{{ publication.reference }}</span>
				</div>
				<div v-if="publication.summary">
					<b>{{ t('opencatalogi', 'Summary') }}:</b>
					<span>{{ publication.summary || '-' }}</span>
				</div>
				<div v-if="publication.description">
					<b>{{ t('opencatalogi', 'Description') }}:</b>
					<span>{{ publication.description || '-' }}</span>
				</div>
				<div v-if="publication.category">
					<b>{{ t('opencatalogi', 'Category') }}:</b>
					<span>{{ publication.category || '-' }}</span>
				</div>
				<div v-if="publication.portal">
					<b>{{ t('opencatalogi', 'Portal') }}:</b>
					<span><a target="_blank" :href="publication.portal">{{
						publication.portal || '-' }}</a></span>
				</div>
				<div v-if="publication.image">
					<b>{{ t('opencatalogi', 'Image') }}:</b>
					<span>{{ publication.image || '-' }}</span>
				</div>
				<div v-if="publication.featured">
					<b>{{ t('opencatalogi', 'Featured') }}:</b>
					<span>{{ publication.featured ? t('opencatalogi', 'Yes') : t('opencatalogi', 'No') }}</span>
				</div>
				<div v-if="publication.license">
					<b>{{ t('opencatalogi', 'License') }}:</b>
					<span>{{ publication.license || '-' }}</span>
				</div>
				<div v-if="publication.status">
					<b>{{ t('opencatalogi', 'Status') }}:</b>
					<span>{{ publication.status || '-' }}</span>
				</div>
				<div v-if="publication.published">
					<b>{{ t('opencatalogi', 'Published') }}:</b>
					<span>{{ new Date(publication.published).toLocaleDateString() || '-' }}</span>
				</div>
				<div v-if="publication.modified">
					<b>{{ t('opencatalogi', 'Modified') }}:</b>
					<span>{{ publication.modified || '-' }}</span>
				</div>
				<div v-if="publication.source">
					<b>{{ t('opencatalogi', 'Source') }}:</b>
					<span>{{ publication.source || '-' }}</span>
				</div>
				<div v-if="publication.catalogi">
					<b>{{ t('opencatalogi', 'Catalog') }}:</b>
					<span v-if="catalogiLoading">{{ t('opencatalogi', 'Loading...') }}</span>
					<div v-if="!catalogiLoading" class="buttonLinkContainer">
						<span>{{ catalogi?.title }}</span>
						<NcActions>
							<NcActionLink :aria-label="`ga naar ${catalogi?.title}`"
								:name="catalogi?.title"
								@click="goToCatalogi()">
								<template #icon>
									<OpenInApp :size="20" />
								</template>
								{{ catalogi?.title }}
							</NcActionLink>
						</NcActions>
					</div>
				</div>
			</div>
			<!-- <div class="detailGrid linksParameters">
				<div>
					<b>Organisatie:</b>
					<span v-if="organizationLoading">Loading...</span>
					<div v-if="!organizationLoading && organization?.title" class="buttonLinkContainer">
						<span>{{ organization?.title }}</span>
						<NcActions>
							<NcActionLink :aria-label="`ga naar ${organization?.title}`"
								:name="organization?.title"
								@click="goToOrganization()">
								<template #icon>
									<OpenInApp :size="20" />
								</template>
								{{ organization?.title }}
							</NcActionLink>
						</NcActions>
					</div>
					<div v-if="!organizationLoading && !organization?.title" class="buttonLinkContainer">
						<span>Geen organisatie gekoppeld</span>
					</div>
				</div>
			</div> -->
		</div>
		<div class="tabContainer">
			<BTabs content-class="mt-3" justified>
				<BTab :title="t('opencatalogi', 'Attachments')" active>
					<div class="tabPanel">
						<div class="buttonsContainer">
							<NcButton type="primary"
								class="fullWidthButton"
								:aria-label="t('opencatalogi', 'Add attachment')"
								@click="addAttachment">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('opencatalogi', 'Add attachment') }}
							</NcButton>
							<NcButton type="secondary"
								:aria-label="t('opencatalogi', 'Open folder')"
								@click="openFolder(publication?.['@self']?.folder)">
								<template #icon>
									<FolderOutline :size="20" />
								</template>
							</NcButton>
							<NcActions :disabled="objectStore.isLoading('publicationAttachments')"
								:primary="true"
								class="checkboxListActionButton"
								:menu-name="objectStore.isLoading('publicationAttachments') ? t('opencatalogi', 'Loading...') : t('opencatalogi', 'Actions')"
								:inline="0"
								:title="t('opencatalogi', 'Actions you can perform on this publication')">
								<template #icon>
									<span>
										<NcLoadingIcon v-if="objectStore.isLoading('publicationAttachments')" :size="20" appearance="dark" />
										<DotsHorizontal v-if="!objectStore.isLoading('publicationAttachments')" :size="20" />
									</span>
								</template>
								<NcActionButton close-after-click @click="selectAllAttachments('published')">
									<template #icon>
										<SelectAllIcon v-if="!allPublishedSelected" :size="20" />
										<SelectRemove v-else :size="20" />
									</template>
									{{ !allPublishedSelected ? t('opencatalogi', 'Select all published attachments') : t('opencatalogi', 'Deselect all published attachments') }}
								</NcActionButton>
								<NcActionButton close-after-click @click="selectAllAttachments('unpublished')">
									<template #icon>
										<SelectAllIcon v-if="!allUnpublishedSelected" :size="20" />
										<SelectRemove v-else :size="20" />
									</template>
									{{ !allUnpublishedSelected ? t('opencatalogi', 'Select all unpublished attachments') : t('opencatalogi', 'Deselect all unpublished attachments') }}
								</NcActionButton>
								<NcActionButton v-if="selectedUnpublishedCount > 0" close-after-click @click="bulkPublish">
									<template #icon>
										<Publish :size="20" />
									</template>
									{{ n('opencatalogi', 'Publish {count} attachment', 'Publish {count} attachments', selectedUnpublishedCount, { count: selectedUnpublishedCount }) }}
								</NcActionButton>
								<NcActionButton v-if="selectedPublishedCount > 0" close-after-click @click="bulkDepublish">
									<template #icon>
										<PublishOff :size="20" />
									</template>
									{{ n('opencatalogi', 'Depublish {count} attachment', 'Depublish {count} attachments', selectedPublishedCount, { count: selectedPublishedCount }) }}
								</NcActionButton>
								<NcActionButton v-if="selectedAttachments.length > 0" close-after-click @click="bulkDeleteAttachments">
									<template #icon>
										<Delete :size="20" />
									</template>
									{{ n('opencatalogi', 'Delete {count} attachment', 'Delete {count} attachments', selectedAttachmentsEntities.length, { count: selectedAttachmentsEntities.length }) }}
								</NcActionButton>
							</NcActions>
						</div>

						<div v-if="publicationAttachments?.length > 0">
							<div v-for="(attachment, i) in publicationAttachments" :key="`${attachment}${i}`" class="checkedItem">
								<NcCheckboxRadioSwitch
									:checked="selectedAttachments.includes(attachment.id)"
									@update:checked="toggleSelection(attachment)" />

								<NcListItem
									:class="`${attachment?.title === editingTags ? 'editingTags' : ''}`"
									:name="attachment?.title"
									:bold="false"
									:active="objectStore.getActiveObject('publicationAttachment')?.id === attachment.id"
									:force-display-actions="true"
									@click="setActiveAttachment(attachment)">
									<template #icon>
										<NcLoadingIcon v-if="fileIdsLoading.includes(attachment.id) && (depublishLoading.includes(attachment.id) || publishLoading.includes(attachment.id) || saveTagsLoading.includes(attachment.id))" :size="44" />
										<ExclamationThick v-else-if="!attachment.accessUrl || !attachment.downloadUrl" class="warningIcon" :size="44" />
										<FileOutline v-else
											class="publishedIcon"
											disable-menu
											:size="44" />
									</template>

									<template #details>
										<span>{{ formatFileSize(attachment?.size) }}</span>
									</template>
									<template #indicator>
										<div v-if="editingTags !== attachment.title" class="fileLabelsContainer">
											<NcCounterBubble v-for="label of attachment.labels" :key="label">
												{{ label }}
											</NcCounterBubble>
										</div>
										<div v-if="editingTags === attachment.title" class="editTagsContainer">
											<NcSelect
												v-model="editedTags"
												class="editTagsSelect"
												:disabled="saveTagsLoading.includes(attachment.id)"
												:taggable="true"
												:multiple="true"
												:aria-label-combobox="labelOptionsEdit.inputLabel"
												:options="labelOptionsEdit.options"
												@tag="addNewTag" />
											<NcButton
												v-tooltip="t('opencatalogi', 'Save labels')"
												class="editTagsButton"
												type="primary"
												:aria-label="t('opencatalogi', 'Save tags for {title}', { title: attachment.title })"
												@click="saveTags(attachment, editedTags)">
												<template #icon>
													<ContentSaveOutline v-if="!saveTagsLoading.includes(attachment.id)" :size="20" />
													<NcLoadingIcon v-if="saveTagsLoading.includes(attachment.id)" :size="20" />
												</template>
											</NcButton>
										</div>
									</template>
									<template #subname>
										{{ attachment?.published ? new Date(attachment?.published).toLocaleDateString() : t('opencatalogi', 'Unpublished') }} - {{ attachment?.type || t('opencatalogi', 'No type') }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="openFile(attachment)">
											<template #icon>
												<OpenInNew :size="20" />
											</template>
											{{ t('opencatalogi', 'View file') }}
										</NcActionButton>
										<NcActionButton v-if="!attachment.published"
											close-after-click
											@click="publishFile(attachment)">
											<template #icon>
												<Publish :size="20" />
											</template>
											{{ t('opencatalogi', 'Publish') }}
										</NcActionButton>
										<NcActionButton v-if="attachment.published"
											close-after-click
											@click="depublishFile(attachment)">
											<template #icon>
												<PublishOff :size="20" />
											</template>
											{{ t('opencatalogi', 'Depublish') }}
										</NcActionButton>
										<NcActionButton close-after-click @click="deleteFile(attachment)">
											<template #icon>
												<Delete :size="20" />
											</template>
											{{ t('opencatalogi', 'Delete') }}
										</NcActionButton>
										<NcActionButton v-if="editingTags !== attachment.title"
											close-after-click
											@click="editTags(attachment)">
											<template #icon>
												<TagEdit :size="20" />
											</template>
											{{ t('opencatalogi', 'Edit tags') }}
										</NcActionButton>
										<NcActionButton v-if="editingTags === attachment.title"
											close-after-click
											@click="editingTags = null; editedTags = []">
											<template #icon>
												<TagOff :size="20" />
											</template>
											{{ t('opencatalogi', 'Stop editing tags') }}
										</NcActionButton>
									</template>
								</NcListItem>
							</div>

							<div class="paginationContainer">
								<BPagination v-model="currentPage" :total-rows="publicationAttachments?.total" :per-page="limit" />
								<div>
									<span>{{ t('opencatalogi', 'Items per page') }}</span>
									<NcSelect v-model="limit"
										:aria-label-combobox="t('opencatalogi', 'Items per page')"
										class="limitSelect"
										:options="limitOptions.options"
										:taggable="true"
										:selectable="(option) => !isNaN(option) && (typeof option !== 'boolean')" />
								</div>
							</div>
						</div>

						<div v-if="publicationAttachments?.length === 0">
							<b class="emptyStateMessage">
								{{ t('opencatalogi', 'No attachments added yet') }}
							</b>
						</div>

						<div
							v-if="publicationAttachments?.length !== 0 && !publicationAttachments?.length > 0">
							<NcLoadingIcon :size="64"
								class="loadingIcon"
								appearance="dark"
								:name="t('opencatalogi', 'Attachments are loading')" />
						</div>
					</div>
				</BTab>
				<!-- <BTab title="Eigenschappen">
					<div class="tabPanel">
						<div class="buttonsContainer">
							<NcButton type="primary"
								class="fullWidthButton"
								aria-label="Bijlage toevoegen"
								@click="navigationStore.setModal('addPublicationData')">
								<template #icon>
									<Plus :size="20" />
								</template>
								Eigenschap toevoegen
							</NcButton>
							<NcActions :disabled="objectStore.isLoading('publication')"
								:primary="true"
								class="checkboxListActionButton"
								:menu-name="objectStore.isLoading('publication') ? 'Laden...' : 'Acties'"
								:inline="0"
								title="Acties die je kan uitvoeren op deze publicatie">
								<template #icon>
									<span>
										<NcLoadingIcon v-if="objectStore.isLoading('publication')" :size="20" appearance="dark" />
										<DotsHorizontal v-if="!objectStore.isLoading('publication')" :size="20" />
									</span>
								</template>
								<NcActionButton close-after-click @click="selectAllPublicationData()">
									<template #icon>
										<SelectAllIcon v-if="!allPublicationDataSelected" :size="20" />
										<SelectRemove v-else :size="20" />
									</template>
									{{ !allPublicationDataSelected ? "Selecteer" : "Deselecteer" }} alle eigenschappen
								</NcActionButton>
								<NcActionButton close-after-click :disabled="selectedPublicationData.length === 0" @click="bulkDeleteEigenschappen">
									<template #icon>
										<Delete :size="20" />
									</template>
									Verwijder {{ selectedPublicationData.length }} eigenschap{{ selectedPublicationData.length > 1 || selectedPublicationData.length === 0 ? 'pen' : '' }}
								</NcActionButton>
							</NcActions>
						</div>
						<div v-if="publication && publication.data && Object.keys(publication.data).length > 0">
							<div v-for="(value, key, i) in publication?.data" :key="`${key}${i}`" class="checkedItem">
								<NcCheckboxRadioSwitch
									:checked="selectedPublicationData.includes(key)"
									@update:checked="togglePublicationDataSelection(key)" />
								<NcListItem
									:name="key"
									:bold="false"
									:force-display-actions="true"
									:active="publicationDataKey === key"
									@click="setActiveDataKey(key)">
									<template #icon>
										<CircleOutline
											:class="publicationDataKey === key && 'selectedZaakIcon'"
											disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ value }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="editPublicationDataItem(key)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Bewerken
										</NcActionButton>
										<NcActionButton close-after-click @click="deletePublicationDataItem(key)">
											<template #icon>
												<Delete :size="20" />
											</template>
											Verwijderen
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
						</div>
					</div>
					<div v-if="!publication || !publication.data || Object.keys(publication.data).length === 0" class="tabPanel">
						<b class="emptyStateMessage">
							Geen eigenschappen gevonden
						</b>
					</div>
				</BTab> -->
				<BTab :title="t('opencatalogi', 'Themes')">
					<div class="tabPanel">
						<div class="buttonsContainer">
							<NcButton type="primary"
								class="fullWidthButton"
								:aria-label="t('opencatalogi', 'Add theme')"
								@click="navigationStore.setModal('addPublicationTheme')">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('opencatalogi', 'Add theme') }}
							</NcButton>
							<NcActions :disabled="objectStore.isLoading('themes')"
								:primary="true"
								class="checkboxListActionButton"
								:menu-name="objectStore.isLoading('themes') ? t('opencatalogi', 'Loading...') : t('opencatalogi', 'Actions')"
								:inline="0"
								:title="t('opencatalogi', 'Actions you can perform on this publication')">
								<template #icon>
									<span>
										<NcLoadingIcon v-if="objectStore.isLoading('themes')" :size="20" appearance="dark" />
										<DotsHorizontal v-if="!objectStore.isLoading('themes')" :size="20" />
									</span>
								</template>
								<NcActionButton close-after-click @click="selectAllThemes()">
									<template #icon>
										<SelectAllIcon v-if="!allThemesSelected" :size="20" />
										<SelectRemove v-else :size="20" />
									</template>
									{{ !allThemesSelected ? t('opencatalogi', 'Select all themes') : t('opencatalogi', 'Deselect all themes') }}
								</NcActionButton>
								<NcActionButton close-after-click :disabled="selectedThemes.length === 0" @click="bulkDeleteThemes">
									<template #icon>
										<Delete :size="20" />
									</template>
									{{ n('opencatalogi', 'Delete {count} theme', 'Delete {count} themes', selectedThemes.length, { count: selectedThemes.length }) }}
								</NcActionButton>
							</NcActions>
						</div>
						<div v-if="filteredThemes?.length || missingThemes?.length">
							<div v-for="(value, key, i) in filteredThemes" :key="`${value.id}${i}`" class="checkedItem">
								<NcCheckboxRadioSwitch
									:checked="selectedThemes.includes(value.id)"
									@update:checked="toggleThemeSelection(value)" />
								<NcListItem
									:name="value.title"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<ShapeOutline
											:class="objectStore.getActiveObject('theme')?.id === value.id && 'selectedZaakIcon'"
											disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ value.summary }}
									</template>
									<template #actions>
										<NcActionButton close-after-click @click="objectStore.setActiveObject('theme', value); $router.push('/themes')">
											<template #icon>
												<OpenInApp :size="20" />
											</template>
											{{ t('opencatalogi', 'View') }}
										</NcActionButton>
										<NcActionButton close-after-click @click="objectStore.setActiveObject('theme', value); navigationStore.setDialog('deletePublicationThemeDialog')">
											<template #icon>
												<Delete :size="20" />
											</template>
											{{ t('opencatalogi', 'Delete') }}
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<NcListItem v-for="(value, key, i) in missingThemes"
								:key="`${value}${i}`"
								:name="t('opencatalogi', 'Theme {id}', { id: value })"
								:bold="false"
								:force-display-actions="true">
								<template #icon>
									<Alert disable-menu
										:size="44" />
								</template>
								<template #subname>
									{{ t('opencatalogi', 'Theme {id} does not exist, it is recommended to delete it from this publication.', { id: value }) }}
								</template>
								<template #actions>
									<NcActionButton close-after-click :disabled="deleteThemeLoading" @click="deleteMissingTheme(value)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('opencatalogi', 'Delete') }}
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<NcListItem v-for="(value, key, i) in missingThemes"
							:key="`${value}${i}`"
							:name="t('opencatalogi', 'Theme {id}', { id: value })"
							:bold="false"
							:force-display-actions="true">
							<template #icon>
								<Alert disable-menu
									:size="44" />
							</template>
							<template #subname>
								{{ t('opencatalogi', 'Theme {id} does not exist, it is recommended to delete it from this publication.', { id: value }) }}
							</template>
							<template #actions>
								<NcActionButton :disabled="deleteThemeLoading" @click="deleteMissingTheme(value)">
									<template #icon>
										<Delete :size="20" />
									</template>
									{{ t('opencatalogi', 'Delete') }}
								</NcActionButton>
							</template>
						</NcListItem>
					</div>
					<div v-if="!filteredThemes?.length && !missingThemes?.length" class="tabPanel">
						<b class="emptyStateMessage">
							{{ t('opencatalogi', 'No themes found') }}
						</b>
					</div>
				</BTab>
				<BTab :title="t('opencatalogi', 'Logging')">
					<table width="100%">
						<tr>
							<th><b>{{ t('opencatalogi', 'Timestamp') }}</b></th>
							<th><b>{{ t('opencatalogi', 'User') }}</b></th>
							<th><b>{{ t('opencatalogi', 'Action') }}</b></th>
							<th><b>{{ t('opencatalogi', 'Details') }}</b></th>
						</tr>
						<tr>
							<td>18-07-2024 11:55:21</td>
							<td>Ruben van der Linde</td>
							<td>{{ t('opencatalogi', 'Created') }}</td>
							<td>
								<NcButton @click="navigationStore.setDialog('viewLog')">
									<template #icon>
										<TimelineQuestionOutline :size="20" />
									</template>
									{{ t('opencatalogi', 'View details') }}
								</NcButton>
							</td>
						</tr>
					</table>
				</BTab>
				<BTab v-if="1 == 2" :title="t('opencatalogi', 'Permissions')">
					<table width="100%">
						<tr>
							<td>{{ t('opencatalogi', 'This publication is') }} <b v-if="prive">{{ t('opencatalogi', 'NOT') }}</b> {{ t('opencatalogi', 'publicly accessible') }}</td>
							<td>
								<NcButton @click="prive = !prive">
									<template #icon>
										<LockOpenVariantOutline v-if="!prive" :size="20" />
										<LockOutline v-if="prive" :size="20" />
									</template>
									<span v-if="!prive">{{ t('opencatalogi', 'Make private') }}</span>
									<span v-if="prive">{{ t('opencatalogi', 'Make public') }}</span>
								</NcButton>
							</td>
						</tr>
						<tr v-if="prive">
							<td>{{ t('opencatalogi', 'User groups') }}</td>
							<td>
								<NcSelectTags v-model="userGroups"
									:input-label="t('opencatalogi', 'User groups')"
									:multiple="true" />
							</td>
						</tr>
					</table>
				</BTab>
				<BTab v-if="1 == 2" :title="t('opencatalogi', 'Statistics')">
					<apexchart v-if="publication.status === 'Published'"
						width="100%"
						type="line"
						:options="chart.options"
						:series="chart.series" />
					<NcNoteCard type="info">
						<p>{{ t('opencatalogi', 'There are no statistics known about this publication') }}</p>
					</NcNoteCard>
				</BTab>
			</BTabs>
		</div>
	</div>
</template>

<script>
import { NcActionButton, NcActions, NcButton, NcListItem, NcLoadingIcon, NcNoteCard, NcSelect, NcSelectTags, NcActionLink, NcCounterBubble, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { BTab, BTabs, BPagination } from 'bootstrap-vue'
import VueApexCharts from 'vue-apexcharts'

// Icons
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import LockOpenVariantOutline from 'vue-material-design-icons/LockOpenVariantOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TagEdit from 'vue-material-design-icons/TagEdit.vue'
import TagOff from 'vue-material-design-icons/TagOff.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import SelectAllIcon from 'vue-material-design-icons/SelectAll.vue'
import SelectRemove from 'vue-material-design-icons/SelectRemove.vue'

export default {
	name: 'PublicationDetail',
	components: {
		NcLoadingIcon,
		NcActionButton,
		NcActions,
		NcButton,
		NcListItem,
		NcSelectTags,
		NcNoteCard,
		NcActionLink,
		NcCheckboxRadioSwitch,
		BTab,
		BTabs,
		apexchart: VueApexCharts,
	},
	data() {
		return {
			test: false,
			selectedAttachments: [],
			limit: '200',
			limitOptions: {
				options: ['10', '20', '50', '100', '200'],
				value: this.limit,
			},

			currentPage: 1,
			totalPages: 1,
			selectedThemes: [],
			editingTitle: '',
			editedTags: [],
			depublishLoading: [],
			publishLoading: [],
			saveTagsLoading: [],
			fileIdsLoading: [],
			selectedPublicationData: [],
			publicationDataKey: '',
			previousPublicationId: null,
			tagsLoading: false,
			labelOptionsEdit: {
				inputLabel: 'Labels',
				multiple: true,
			},
			editingTags: null,
		}
	},
	computed: {
		publicationAttachments() {
			const attachments = objectStore.getCollection('publicationAttachments').results
			if (!attachments) return { results: [], page: 1, total: 0 }

			this.currentPage = attachments.page || 1
			this.totalPages = attachments.total || 1
			return attachments.results || []
		},
		publication() {
			return objectStore.getActiveObject('publication')
		},
		registerId() {
			return this.publication['@self'].register
		},
		schemaId() {
			return this.publication['@self'].schema
		},
		publicationId() {
			return this.publication.id
		},
		filteredThemes() {
			const themes = objectStore.getCollection('theme').results
			return themes.filter((theme) => this.publication?.themes?.includes(theme.id))
		},
		missingThemes() { // themes (id's)- which are on the publication but do not exist on the themeList
			const themes = objectStore.getCollection('theme').results

			return this.publication?.themes?.filter((themeId) => !themes.map((theme) => theme.id).includes(themeId))
		},
		allPublishedSelected() {
			const published = this.publicationAttachments?.filter(item => !!item.published)
				.map(item => item.id) || []

			if (!published.length) {
				return false
			}
			return published.every(pubId => this.selectedAttachments.includes(pubId))
		},
		allUnpublishedSelected() {
			const unpublished = this.publicationAttachments?.filter(item => !item.published)
				.map(item => item.id) || []

			if (!unpublished.length) {
				return false
			}
			return unpublished.every(unpubId => this.selectedAttachments.includes(unpubId))
		},
	},
	watch: {
		// React to route id changes by delegating to a named method
		'$route.params.id': 'onRouteIdChange',
	},
	mounted() {
		// Ensure the active publication is loaded based on the router param, then load attachments
		this.syncWithRoute()
		this.getTags().then(({ response, data }) => {
			this.labelOptionsEdit.options = data
		})
	},
	updated() {
		const currentPublicationId = objectStore.getActiveObject('publication')?.id
		if (currentPublicationId && currentPublicationId !== this.previousPublicationId) {
			this.previousPublicationId = currentPublicationId
			catalogStore.getPublicationAttachments()
		}
	},
	methods: {
		/**
		 * Handle route id changes to load a different publication and its attachments
		 * @param {string} newId - New publication ID from the route
		 * @param {string} oldId - Previous publication ID from the route
		 * @return {void}
		 */
		onRouteIdChange(newId, oldId) {
			if (newId && newId !== oldId) {
				this.syncWithRoute()
			}
		},

		/**
		 * Ensure active publication is loaded from the current route and attachments are fetched
		 * @return {Promise<void>}
		 */
		async syncWithRoute() {
			const id = this.$route?.params?.id
			if (!id) return

			await this.ensurePublicationLoadedFromRoute(id)
			await catalogStore.getPublicationAttachments()
		},

		/**
		 * Load publication by id via store and set it as active (includes @self.schema and @self.register)
		 * @param {string} id - Publication ID
		 * @return {Promise<void>}
		 */
		async ensurePublicationLoadedFromRoute(id) {
			try {
				// If already active and matches, skip fetch
				if (objectStore.getActiveObject('publication')?.id === id) {
					this.previousPublicationId = id
					return
				}

				await objectStore.fetchObject('publication', id, { _extend: '@self.schema,@self.register' })
				const publication = objectStore.getObject('publication', id)
				if (publication) {
					await objectStore.setActiveObject('publication', publication)
					this.previousPublicationId = id
				}
			} catch (e) {
				console.error('Failed to load publication from route:', e)
			}
		},
		formatFileSize(size) {
			if (size < 1024) return size + ' bytes'
			if (size < 1024 * 1024) return (size / 1024).toFixed(2) + ' KB'
			if (size < 1024 * 1024 * 1024) return (size / (1024 * 1024)).toFixed(2) + ' MB'
			return (size / (1024 * 1024 * 1024)).toFixed(2) + ' GB'
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
		addAttachment() {
			navigationStore.setModal('uploadFiles')
		},
		openFolder(folder) {
			window.open(folder, '_blank')
		},
		selectedPublishedCount() {
			return this.selectedAttachments.filter((a) => {
				const found = this.publicationAttachments
					?.find(item => item.id === a)
				if (!found) return false

				return !!found.published
			}).length
		},
		selectedUnpublishedCount() {
			return this.selectedAttachments.filter((a) => {
				const found = this.publicationAttachments?.find(item => item.id === a)
				if (!found) return false
				return found.published === null
			}).length
		},
		selectedAttachmentsEntities() {
			return this.publicationAttachments?.filter(attach => this.selectedAttachments.includes(attach.id)) || []
		},
		editTags(attachment) {
			this.editingTags = attachment.title
			this.editedTags = attachment.labels
		},
		addNewTag(newTag) {
			if (!newTag) return
			if (!this.labelOptionsEdit.options || !Array.isArray(this.labelOptionsEdit.options)) {
				this.labelOptionsEdit.options = []
			}
			if (!this.labelOptionsEdit.options.includes(newTag)) {
				this.labelOptionsEdit.options = [...this.labelOptionsEdit.options, newTag]
			}
			if (!this.editedTags?.includes(newTag)) {
				this.editedTags = [...(this.editedTags || []), newTag]
			}
		},
		async getTags() {
			const response = await fetch(
				'/index.php/apps/openregister/api/tags',
				{ method: 'get' },
			)
			const data = await response.json()

			// const filteredData = data.filter((tag) => !tag.startsWith('object:'))

			// this.setTagsList(filteredData)
			return { response, data }
		},
		async saveTags(attachment) {
			this.saveTagsLoading.push(attachment.id)
			this.fileIdsLoading.push(attachment.id)

			const formData = new FormData()
			this.editedTags && this.editedTags.forEach((tag) => {
				formData.append('tags[]', tag)
			})

			const publication = objectStore.getActiveObject('publication')
			const register = publication['@self'].register
			const schema = publication['@self'].schema
			const id = publication.id

			await fetch(`/index.php/apps/openregister/api/objects/${register}/${schema}/${id}/files/${attachment.id}`, {
				method: 'PUT',
				body: JSON.stringify({
					tags: this.editedTags,
				}),
				headers: {
					'Content-Type': 'application/json',
				},
			}).then((response) => {
				this.editingTags = null
				this.editedTags = []
			}).catch((err) => {
				console.error(err)
			}).finally(() => {
				this.getTags().then(({ response, data }) => {
					this.labelOptionsEdit.options = data
				})
				catalogStore.getPublicationAttachments({ page: this.currentPage, limit: this.limit }).finally(() => {
					this.saveTagsLoading.splice(this.saveTagsLoading.indexOf(attachment.id), 1)
					this.fileIdsLoading.splice(this.fileIdsLoading.indexOf(attachment.id), 1)
				})
			})
		},

		toggleSelection(attachment) {
			this.selectedAttachments = this.selectedAttachments.includes(attachment.id) ? this.selectedAttachments.filter(id => id !== attachment.id) : [...this.selectedAttachments, attachment.id]
		},
		selectAllPublicationData() {
			const keys = this.publication?.data ? Object.keys(this.publication.data) : []
			if (!keys.length) return

			if (!this.allPublicationDataSelected) {
				this.selectedPublicationData = keys
			} else {
				this.selectedPublicationData = []
			}
		},
		allPublicationDataSelected() {
			const keys = this.publication?.data ? Object.keys(this.publication.data) : []
			if (!keys.length) return false
			return keys.every(key => this.selectedPublicationData.includes(key))
		},
		publishPublication(mode) {
			fetch(`/index.php/apps/openregister/api/objects/${objectStore.getActiveObject('publication')['@self'].register}/${objectStore.getActiveObject('publication')['@self'].schema}/${objectStore.getActiveObject('publication').id}/${mode}`, {
				method: 'POST',
			}).then((response) => {
				catalogStore.fetchPublications()
				response.json().then((data) => {
					objectStore.setActiveObject('publication', { ...data, id: data.id || data['@self'].id })
				})
			})
		},
		publishFile(attachment) {
			this.publishLoading.push(attachment.id)
			this.fileIdsLoading.push(attachment.id)

			return fetch(`/index.php/apps/openregister/api/objects/${this.registerId}/${this.schemaId}/${this.publicationId}/files/${attachment.id}/publish`, {
				method: 'POST',
			}).catch((error) => {
				console.error('Error publishing file:', error)
			}).finally(() => {
				catalogStore.getPublicationAttachments().finally(() => {
					this.publishLoading.splice(this.publishLoading.indexOf(attachment.id), 1)
					this.fileIdsLoading.splice(this.fileIdsLoading.indexOf(attachment.id), 1)
				})
				catalogStore.fetchPublications()
			})
		},
		depublishFile(attachment) {
			this.depublishLoading.push(attachment.id)
			this.fileIdsLoading.push(attachment.id)

			return fetch(`/index.php/apps/openregister/api/objects/${this.registerId}/${this.schemaId}/${this.publicationId}/files/${attachment.id}/depublish`, {
				method: 'POST',
			}).catch((error) => {
				console.error('Error depublishing file:', error)
			}).finally(() => {
				catalogStore.getPublicationAttachments().finally(() => {
					this.depublishLoading.splice(this.depublishLoading.indexOf(attachment.id), 1)
					this.fileIdsLoading.splice(this.fileIdsLoading.indexOf(attachment.id), 1)
				})
				catalogStore.fetchPublications()
			})
		},
		deleteFile(attachment) {
			objectStore.setActiveObject('publicationAttachment', attachment)
			// publicationStore.setAttachmentItem(attachment)
			// publicationStore.setCurrentPage(this.currentPage)
			// publicationStore.setLimit(this.limit)
			navigationStore.setDialog('deleteAttachment')
		},
		setActiveAttachment(attachment) {
			if (JSON.stringify(objectStore.getActiveObject('publicationAttachment')) === JSON.stringify(attachment)) {
				objectStore.setActiveObject('publicationAttachment', false)
			} else { objectStore.setActiveObject('publicationAttachment', attachment) }
		},
		bulkPublish() {
			const unpublishedAttachments = this.publicationAttachments?.filter(
				attachment =>
					this.selectedAttachments.includes(attachment.id) && !attachment.published,
			) || []

			const promises = unpublishedAttachments.map(async attachment => {
				this.publishLoading.push(attachment.id)
				return await this.publishFile(attachment)
			})

			Promise.all(promises).then(() => {
				catalogStore.getPublicationAttachments(this.publication.id, {
					page: this.currentPage,
					limit: this.limit,
				})
				this.selectedAttachments = []
				this.publishLoading = this.publishLoading.filter(id => id !== this.publication.id)
			})
		},
		bulkDepublish() {
			const publishedAttachments = this.publicationAttachments?.results
				?.filter(
					attachment =>
						this.selectedAttachments.includes(attachment.id) && attachment.published,
				) || []

			const promises = publishedAttachments.map(async attachment => {
				this.depublishLoading.push(attachment.id)
				return await this.depublishFile(attachment)
			})

			Promise.all(promises).then(() => {
				catalogStore.getPublicationAttachments(this.publication.id, {
					page: this.currentPage,
					limit: this.limit,
				})
				this.selectedAttachments = []
				this.depublishLoading = this.depublishLoading.filter(id => id !== this.publication.id)
			})
		},
		bulkDeleteEigenschappen() {
			if (!this.selectedPublicationData.length) return
			navigationStore.setDialog('deleteMultiplePublicationData')
		},

		togglePublicationDataSelection(key) {
			if (this.selectedPublicationData.includes(key)) {
				this.selectedPublicationData = this.selectedPublicationData.filter(k => k !== key)
			} else {
				this.selectedPublicationData.push(key)
			}
		},

		selectAllThemes() {
			const themes = this.filteredThemes?.map(theme => theme.id) || []
			if (!themes.length) return

			if (!this.allThemesSelected) {
				this.selectedThemes = themes
			} else {
				this.selectedThemes = []
			}
		},

		allThemesSelected() {
			const themes = this.filteredThemes?.map(theme => theme.id) || []
			if (!themes.length) return false
			return themes.every(themeId => this.selectedThemes.includes(themeId))
		},

		bulkDeleteThemes() {
			if (!this.selectedThemes.length) return
			navigationStore.setDialog('deleteMultipleThemes')
		},
		onBulkDeleteThemesDone() {
			navigationStore.setDialog(false)
			this.selectedThemes = []
		},
		onBulkDeleteThemesCancel() {
			navigationStore.setDialog(false)
		},

		toggleThemeSelection(theme) {
			if (this.selectedThemes.includes(theme.id)) {
				this.selectedThemes = this.selectedThemes.filter(id => id !== theme.id)
			} else {
				this.selectedThemes.push(theme.id)
			}
		},
	},
}
</script>
<style>

.editingTags > div > a {
	height: auto !important;
}
</style>
<style scoped>
h4 {
	font-weight: bold;
}

.head {
	display: flex;
	justify-content: space-between;
}

.button {
	max-height: 10px;
}

.h1 {
	display: block !important;
	font-size: 2em !important;
	margin-block-start: 0.67em !important;
	margin-block-end: 0.67em !important;
	margin-inline-start: 0px !important;
	margin-inline-end: 0px !important;
	font-weight: bold !important;
	unicode-bidi: isolate !important;
}

.dataContent {
	display: flex;
	flex-direction: column;
}

.fileLabelsContainer {
	display: inline-flex;
	gap: 3px;
}

.active.publicationDetails-actionsDelete {
	background-color: var(--color-error) !important;
}

.active.publicationDetails-actionsDelete button {
	color: #EBEBEB !important;
}

.PublicationDetail-clickable {
	cursor: pointer !important;
}

.buttonLinkContainer {
	display: inline-flex;
	align-items: center;
}

.flex-hor {
	display: flex;
	gap: 4px;
}

.float-right {
	float: right;
}

.buttonsContainer {
	display: flex;
	gap: 10px;
	margin-block-end: 20px;
}

.fullWidthButton {
	width: 100%;
	max-width: 300px;
}

.selectedFileIcon {
	color: var(--color-primary);
}

.editTagsContainer {
	display: flex;
}

.editTagsSelect {
	max-width: 400px;
	margin: 0
}

.editTagsButton {
	height: fit-content;
	align-self: center;
	margin-inline-start: 3px;
}
.linksParameters {
	margin-top: 25px;
}
.emptyStateMessage {
    margin-block-start: 15px;
    text-align: center;
    display: flex;
    justify-content: center;
}

.paginationContainer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-block-start: 10px;
}

.pagination {
	margin-block-start: 0px !important;
}

.limitSelect {
	margin-block-end: 0px;
}

.checkboxListActionButton {
	margin-inline-start: auto;
}
.checkedItem {
	display: flex;
	align-items: center;
}
</style>
