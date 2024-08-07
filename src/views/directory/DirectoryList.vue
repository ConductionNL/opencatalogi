<script setup>
import { navigationStore, directoryStore } from '../../store/store.js'
</script>

<template>
	<ul>
		<div class="listHeader">
			<NcTextField class="searchField"
				:value.sync="search"
				label="Zoeken"
				trailing-button-icon="close"
				:show-trailing-button="search !== ''"
				@trailing-button-click="search = ''">
				<Magnify :size="20" />
			</NcTextField>
			<NcActions>
				<NcActionButton
					title="Bekijk de documentatie over catalogi"
					@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/directory', '_blank')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					Help
				</NcActionButton>
				<NcActionButton :disabled="loading" @click="fetchData">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Ververs
				</NcActionButton>
				<NcActionButton @click="navigationStore.setModal('addDirectory')">
					<template #icon>
						<Plus :size="20" />
					</template>
					Directory inlezen
				</NcActionButton>
			</NcActions>
		</div>

		<div v-if="!loading">
			<NcListItem v-for="(listing, i) in directoryStore.listingList"
				:key="`${listing}${i}`"
				:name="listing.name ?? listing.title"
				:active="directoryStore.listingItem?.id === listing?.id"
				:details="'1h'"
				:counter-number="45"
				@click="directoryStore.setListingItem(listing)">
				<template #icon>
					<LayersOutline :class="directoryStore.listingItem?.id === listing?.id && 'selectedIcon'"
						disable-menu
						:size="44" />
				</template>
				<template #subname>
					{{ listing?.title }}
				</template>
				<template #actions>
					<NcActionButton @click="directoryStore.setListingItem(listing); navigationStore.setModal('editListing')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Bewerken
					</NcActionButton>
					<NcActionButton @click="directoryStore.setListingItem(listing); navigationStore.setDialog('deleteListing')">
						<template #icon>
							<Delete :size="20" />
						</template>
						Verwijderen
					</NcActionButton>
				</template>
			</NcListItem>
		</div>

		<NcLoadingIcon v-if="loading"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Listings aan het laden" />

		<NcEmptyContent
			v-if="!directoryStore.listingList?.length > 0"
			class="detailContainer"
			name="Geen Listings"
			description="Je directory of zoek opdracht bevat nog geen listings, wil je een externe directory toevoegen?">
			<template #icon>
				<LayersOutline />
			</template>
			<template #action>
				<NcButton type="primary" @click="navigationStore.setModal('addDirectory')">
					<template #icon>
						<Plus :size="20" />
					</template>
					Directory inlezen
				</NcButton>
				<NcButton @click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/directory', '_blank')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					Meer informatie over de directory
				</NcButton>
			</template>
		</NcEmptyContent>
	</ul>
</template>
<script>
import { NcListItem, NcActionButton, NcTextField, NcLoadingIcon, NcActions, NcEmptyContent, NcButton } from '@nextcloud/vue'
// eslint-disable-next-line n/no-missing-import
import Magnify from 'vue-material-design-icons/Magnify'
// eslint-disable-next-line n/no-missing-import
import LayersOutline from 'vue-material-design-icons/LayersOutline'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import { debounce } from 'lodash'

export default {
	name: 'DirectoryList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		// Icons
		LayersOutline,
		Magnify,
		HelpCircleOutline,
		Refresh,
		Plus,
		Pencil,
		Delete,
	},
	beforeRouteLeave(to, from, next) {
		this.search = ''
		next()
	},
	props: {
		searchQuery: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
		}
	},
	watch: {
		searchQuery: {
			handler(searchQuery) {
				this.debouncedFetchData(searchQuery)
			},
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		fetchData(search = null) {
			this.loading = true
			directoryStore.refreshListingList(search)
				.then(() => {
					this.loading = false
				})
		},
		debouncedFetchData: debounce(function(search) {
			this.fetchData(search)
		}, 500),
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>
<style>
.listHeader {
    position: sticky;
    top: 0;
    z-index: 1000;
    background-color: var(--color-main-background);
    border-bottom: 1px solid var(--color-border);
}

.searchField {
    padding-inline-start: 65px;
    padding-inline-end: 20px;
    margin-block-end: 6px;
}

.selectedIcon>svg {
    fill: white;
}

.loadingIcon {
    margin-block-start: var(--OC-margin-20);
}
</style>
