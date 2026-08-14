<!--
	UNREACHABLE COMPONENT — no visual baseline is possible.

	Nothing imports this file: `src/registry.js` is the only place page
	components are handed to CnAppRoot, and it does not list it. (The single
	`grep` hit outside this file is a prose comment in
	`src/sidebars/dashboard/DashboardSideBar.vue`, not an import.)
	`/publications/:catalogSlug` is a manifest `type: "index"` page rendered by
	nc-vue's generic CnIndexPage from `src/manifest.json`, so this view is
	superseded migration debris. Confirmed by grepping the built bundle: its
	`name: 'PublicationList'` option occurs 0 times in
	`js/opencatalogi-main.js`, while the six wired views each occur once —
	webpack tree-shakes it out entirely.

	See src/views/directory/DirectoryIndex.vue for the full rationale.

	@visual exclude Unreachable: imported by nothing, in no route, tree-shaken out of the shipped bundle; superseded by the manifest type:"index" Publications page (CnIndexPage). Tracked in ConductionNL/opencatalogi#849.
-->
<script setup>
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="listHeader">
				<NcTextField
					class="searchField"
					:modelValue="objectStore.getSearchTerm('publication')"
					:label="t('opencatalogi', 'Search')"
					trailingButtonIcon="close"
					:showTrailingButton="
						objectStore.getSearchTerm('publication') !== ''
					"
					@update:modelValue="
						objectStore.setSearchTerm('publication', $event)
					"
					@trailingButtonClick="
						objectStore.clearSearchTerm('publication')
					">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionCaption :name="t('opencatalogi', 'Search')" />
					<NcActionCheckbox
						:modelValue="conceptChecked"
						value="concept"
						@change="handleCheckboxChange('concept', $event)">
						{{ t('opencatalogi', 'Concept') }}
					</NcActionCheckbox>
					<NcActionCheckbox
						:modelValue="gepubliceerdChecked"
						value="gepubliceerd"
						@change="handleCheckboxChange('gepubliceerd', $event)">
						{{ t('opencatalogi', 'Published') }}
					</NcActionCheckbox>
					<NcActionSeparator />
					<NcActionCaption :name="t('opencatalogi', 'Sort')" />
					<NcActionInput
						v-model="sortField"
						type="multiselect"
						:inputLabel="t('opencatalogi', 'Property')"
						:options="['Title', 'Published date', 'Modified date']">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('opencatalogi', 'Choose a property') }}
					</NcActionInput>
					<NcActionRadio
						:modelValue="sortDirection"
						name="sortDirection"
						value="asc"
						@update:modelValue="updateSortOrder('asc')">
						{{ t('opencatalogi', 'Ascending') }}
					</NcActionRadio>
					<NcActionRadio
						:modelValue="sortDirection"
						name="sortDirection"
						value="desc"
						@update:modelValue="updateSortOrder('desc')">
						{{ t('opencatalogi', 'Descending') }}
					</NcActionRadio>
					<NcActionSeparator />
					<NcActionCaption :name="t('opencatalogi', 'Actions')" />
					<NcActionButton
						:title="
							t(
								'opencatalogi',
								'View the documentation about publications',
							)
						"
						@click="
							openLink(
								'https://opencatalogi.conduction.nl/docs/Users/publicaties/',
								'_blank',
							)
						">
						<template #icon>
							<HelpCircleOutline :size="20" />
						</template>
						{{ t('opencatalogi', 'Help') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						:disabled="catalogStore.isLoading"
						@click="catalogStore.fetchPublications">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('opencatalogi', 'Refresh') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								objectStore.clearActiveObject('publication')
								navigationStore.setModal('objectModal')
							}
						">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('opencatalogi', 'Add publication') }}
					</NcActionButton>
				</NcActions>
			</div>
			<div
				v-if="
					!catalogStore.isLoading || !objectStore.isLoading('publication')
				">
				<NcListItem
					v-for="(publication, i) in publicationsResults"
					:key="`${publication}${i}`"
					:name="
						publication.title
						|| publication.name
						|| publication.titel
						|| publication.naam
						|| publication.id
					"
					:bold="false"
					:forceDisplayActions="true"
					:active="$route?.params?.id === publication.id"
					:details="publication?.status"
					@click="toggleActive(publication)">
					<template #icon>
						<PublishedIcon :object="publication" :size="44" />
					</template>
					<template #subname>
						{{ publication?.summary }}
					</template>
					<template #actions>
						<NcActionButton
							closeAfterClick
							@click="
								() => {
									objectStore.setActiveObject(
										'publication',
										publication,
									)
									navigationStore.setModal('objectModal')
								}
							">
							<template #icon>
								<Pencil :size="20" />
							</template>
							{{ t('opencatalogi', 'Edit') }}
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							@click="
								() => {
									objectStore.setActiveObject(
										'publication',
										publication,
									)
									navigationStore.setDialog('copyObject', {
										objectType: 'publication',
										dialogTitle: 'Publication',
									})
								}
							">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							{{ t('opencatalogi', 'Copy') }}
						</NcActionButton>
						<NcActionButton
							v-if="publication['@self'].published === null"
							closeAfterClick
							@click="
								() => {
									objectStore.setActiveObject(
										'publication',
										publication,
									)
									publishPublication('publish')
								}
							">
							<template #icon>
								<Publish :size="20" />
							</template>
							{{ t('opencatalogi', 'Publish') }}
						</NcActionButton>
						<NcActionButton
							v-if="publication['@self'].published"
							closeAfterClick
							@click="
								() => {
									objectStore.setActiveObject(
										'publication',
										publication,
									)
									publishPublication('depublish')
								}
							">
							<template #icon>
								<PublishOff :size="20" />
							</template>
							{{ t('opencatalogi', 'Depublish') }}
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							@click="
								() => {
									objectStore.setActiveObject(
										'publication',
										publication,
									)
									navigationStore.setTransferData({
										initialTab: 'files',
									})
									navigationStore.setModal('viewObject')
								}
							">
							<template #icon>
								<FilePlusOutline :size="20" />
							</template>
							{{ t('opencatalogi', 'Add attachment') }}
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							class="publicationsList-actionsDelete"
							@click="
								() => {
									objectStore.setActiveObject(
										'publication',
										publication,
									)
									navigationStore.setDialog('deleteObject', {
										objectType: 'publication',
										dialogTitle: 'Publicatie',
									})
								}
							">
							<template #icon>
								<Delete :size="20" />
							</template>
							{{ t('opencatalogi', 'Delete') }}
						</NcActionButton>
					</template>
				</NcListItem>
			</div>

			<NcLoadingIcon
				v-if="catalogStore.isLoading"
				:size="64"
				class="loadingIcon"
				appearance="dark"
				:name="t('opencatalogi', 'Publications are loading')" />

			<div v-if="!publicationsResults?.length" class="emptyListHeader">
				{{ t('opencatalogi', 'There are no publications defined.') }}
			</div>
		</ul>
	</NcAppContentList>
</template>

<script>
import {
	NcActionButton,
	NcActionCaption,
	NcActionCheckbox,
	NcActionInput,
	NcActionRadio,
	NcActions,
	NcActionSeparator,
	NcAppContentList,
	NcListItem,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FilePlusOutline from 'vue-material-design-icons/FilePlusOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
// Icons
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import PublishedIcon from '../../components/PublishedIcon.vue'

export default {
	name: 'PublicationList',
	components: {
		NcListItem,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		Magnify,
		NcLoadingIcon,
		NcActionRadio,
		NcActionCheckbox,
		NcActionInput,
		NcActionCaption,
		NcActionSeparator,
		NcActions,
		// Icons
		Refresh,
		Plus,
		FilePlusOutline,
		ContentCopy,
		Pencil,
		Publish,
		HelpCircleOutline,
		// Components
		PublishedIcon,
	},

	data() {
		return {
			sortField: '',
			sortDirection: 'desc',
			conceptChecked: false,
			gepubliceerdChecked: false,
		}
	},

	computed: {
		publicationsResults() {
			return objectStore.getCollection('publication').results
		},
	},

	methods: {
		updateSortOrder(value) {
			this.sortDirection = value
		},

		publishPublication(mode) {
			const publication = objectStore.getActiveObject('publication')
			fetch(
				`/index.php/apps/openregister/api/objects/${publication['@self'].register}/${publication['@self'].schema}/${publication.id}/${mode}`,
				{
					method: 'POST',
				},
			).then((response) => {
				catalogStore.fetchPublications()
				response.json().then((data) => {
					objectStore.setActiveObject('publication', {
						...data,
						id: data.id || data['@self'].id,
					})
				})
			})
		},

		toggleActive(publication) {
			objectStore.setActiveObject('publication', publication)
			this.$router.push(
				`/publications/${this.$route?.params?.catalogSlug}/${publication.id}`,
			)
		},

		handleCheckboxChange(key, event) {
			const checked = event.target.checked

			if (key === 'concept') {
				this.conceptChecked = checked
			} else if (key === 'gepubliceerd') {
				this.gepubliceerdChecked = checked
			}
		},

		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>

<style>
.listHeader {
	display: flex;
}

.refresh {
	margin-block-start: 11px !important;
	margin-block-end: 11px !important;
	margin-inline-end: 10px;
}

.active.publicationDetails-actionsDelete {
	background-color: var(--color-error) !important;
}

.active.publicationDetails-actionsDelete button {
	color: var(--color-error-text) !important;
}

.loadingIcon {
	margin-block-start: var(--OC-margin-20);
}
</style>
