<script setup>
import { navigationStore, objectStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="listHeader">
				<NcTextField class="searchField"
					:value="objectStore.getSearchTerm('publication')"
					label="Search"
					trailing-button-icon="close"
					:show-trailing-button="objectStore.getSearchTerm('publication') !== ''"
					@update:value="objectStore.setSearchTerm('publication', $event)"
					@trailing-button-click="objectStore.clearSearch('publication')">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionCaption name="Search" />
					<NcActionCheckbox
						:checked="conceptChecked"
						:value="'concept'"
						@change="handleCheckboxChange('concept', $event)">
						Concept
					</NcActionCheckbox>
					<NcActionCheckbox
						:checked="gepubliceerdChecked"
						:value="'gepubliceerd'"
						@change="handleCheckboxChange('gepubliceerd', $event)">
						Published
					</NcActionCheckbox>
					<NcActionSeparator />
					<NcActionCaption name="Sort" />
					<NcActionInput
						v-model="sortField"
						type="multiselect"
						input-label="Property"
						:options="['Title', 'Published date', 'Modified date']">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Choose a property
					</NcActionInput>
					<NcActionRadio
						:checked="sortDirection === 'asc'"
						name="sortDirection"
						value="asc"
						@update:checked="updateSortOrder('asc')">
						Ascending
					</NcActionRadio>
					<NcActionRadio
						:checked="sortDirection === 'desc'"
						name="sortDirection"
						value="desc"
						@update:checked="updateSortOrder('desc')">
						Descending
					</NcActionRadio>
					<NcActionSeparator />
					<NcActionCaption name="Actions" />
					<NcActionButton
						title="View the documentation about publications"
						@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers/publicaties', '_blank')">
						<template #icon>
							<HelpCircleOutline :size="20" />
						</template>
						Help
					</NcActionButton>
					<NcActionButton close-after-click :disabled="catalogStore.isLoading" @click="catalogStore.fetchPublications">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="objectStore.clearActiveObject('publication'); navigationStore.setModal('objectModal')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add publication
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="!catalogStore.isLoading || !objectStore.isLoading('publication')">
				<NcListItem v-for="(publication, i) in publicationsResults"
					:key="`${publication}${i}`"
					:name="publication.title || publication.name || publication.titel || publication.naam || publication.id"
					:bold="false"
					:force-display-actions="true"
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
						<NcActionButton v-if="publication['@self'].published === null" close-after-click @click="objectStore.setActiveObject('publication', publication); publishPublication('publish')">
							<template #icon>
								<Publish :size="20" />
							</template>
							Publish
						</NcActionButton>
						<NcActionButton v-if="publication['@self'].published" close-after-click @click="objectStore.setActiveObject('publication', publication); publishPublication('depublish')">
							<template #icon>
								<PublishOff :size="20" />
							</template>
							Depublish
						</NcActionButton>
						<NcActionButton close-after-click @click="objectStore.setActiveObject('publication', publication); navigationStore.setModal('AddAttachment')">
							<template #icon>
								<FilePlusOutline :size="20" />
							</template>
							Add attachment
						</NcActionButton>
						<NcActionButton close-after-click class="publicationsList-actionsDelete" @click="objectStore.setActiveObject('publication', publication); navigationStore.setDialog('deleteObject', { objectType: 'publication', dialogTitle: 'Publicatie' })">
							<template #icon>
								<Delete :size="20" />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>

			<NcLoadingIcon v-if="catalogStore.isLoading"
				:size="64"
				class="loadingIcon"
				appearance="dark"
				name="Publications are loading" />

			<div v-if="!publicationsResults?.length" class="emptyListHeader">
				There are no publications defined.
			</div>
		</ul>
	</NcAppContentList>
</template>
<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActionRadio, NcActionCheckbox, NcActionInput, NcActionCaption, NcActionSeparator, NcActions } from '@nextcloud/vue'

// Icons
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import FilePlusOutline from 'vue-material-design-icons/FilePlusOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'

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
			fetch(`/index.php/apps/openregister/api/objects/${publication['@self'].register}/${publication['@self'].schema}/${publication.id}/${mode}`, {
				method: 'POST',
			}).then((response) => {
				catalogStore.fetchPublications()
				response.json().then((data) => {
					objectStore.setActiveObject('publication', { ...data, id: data.id || data['@self'].id })
				})
			})
		},
		toggleActive(publication) {
			objectStore.setActiveObject('publication', publication)
			this.$router.push(`/publications/${this.$route?.params?.catalogSlug}/${publication.id}`)
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
    color: #EBEBEB !important;
}

.loadingIcon {
    margin-block-start: var(--OC-margin-20);
}
</style>
