<script setup>
import { navigationStore, organisationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div class="head">
			<h1 class="h1">
				{{ organisation.title }}
			</h1>

			<NcActions
				:disabled="loading"
				:primary="true"
				:menu-name="loading ? 'Laden...' : 'Acties'"
				:inline="1"
				title="Acties die je kan uitvoeren op deze publicatie">
				<template #icon>
					<span>
						<NcLoadingIcon v-if="loading"
							:size="20"
							appearance="dark" />
						<DotsHorizontal v-if="!loading" :size="20" />
					</span>
				</template>
				<NcActionButton
					title="Bekijk de documentatie over organisaties"
					@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/organisaties')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					Help
				</NcActionButton>
				<NcActionButton @click="navigationStore.setModal('editOrganisation')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Bewerken
				</NcActionButton>
				<NcActionButton @click="navigationStore.setDialog('copyOrganisation')">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					Kopiëren
				</NcActionButton>
				<NcActionButton @click="navigationStore.setDialog('deleteOrganisation')">
					<template #icon>
						<Delete :size="20" />
					</template>
					Verwijderen
				</NcActionButton>
			</NcActions>
		</div>
		<div class="container">
			<div class="detailGrid">
				<div>
					<b>Samenvatting:</b>
					<span>{{ organisation.summary }}</span>
				</div>
				<div>
					<b>Beschrijving:</b>
					<span>{{ organisation.description }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
// Components
import { NcActionButton, NcActions, NcLoadingIcon } from '@nextcloud/vue'

// Icons
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'OrganisationDetail',
	components: {
		// Components
		NcLoadingIcon,
		NcActionButton,
		NcActions,
		// Icons
		DotsHorizontal,
		Pencil,
		Delete,
		ContentCopy,
		HelpCircleOutline,
	},
	props: {
		organisationItem: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			organisation: [],
			prive: false,
			loading: false,
			catalogiLoading: false,
			metaDataLoading: false,
			hasUpdated: false,
			userGroups: [
				{
					id: '1',
					label: 'Content Beheerders',
				},
			],
			chart: {
				options: {
					chart: {
						id: 'Aantal bekeken publicaties',
					},
					xaxis: {
						categories: ['7-11', '7-12', '7-13', '7-15', '7-16', '7-17', '7-18'],
					},
				},
				series: [{
					name: 'Weergaven',
					data: [0, 0, 0, 0, 0, 0, 15],
				}],
			},
			upToDate: false,
		}
	},
	watch: {
		organisationItem: {
			handler(newOrganisationItem, oldOrganisationItem) {
				// why this? because when you fetch a new item it changes the reference to said item, which in return causes it to fetch again (a.k.a. infinite loop)
				// run the fetch only once to update the item
				if (!this.upToDate || JSON.stringify(newOrganisationItem) !== JSON.stringify(oldOrganisationItem)) {
					this.organisation = newOrganisationItem
					newOrganisationItem && this.fetchData(newOrganisationItem?.id)
					this.upToDate = true
				}
			},
			deep: true,
		},

	},
	mounted() {
		this.organisation = organisationStore.organisationItem
		organisationStore.organisationItem && this.fetchData(organisationStore.organisationItem.id)
	},
	methods: {
		fetchData(id) {
			organisationStore.getOneOrganisation(id)
				.then(({ response, data }) => {
					this.organisation = data
				})
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>

<style>
h4 {
  font-weight: bold;
}

.head{
	display: flex;
	justify-content: space-between;
}

.button{
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

.active.organisationDetails-actionsDelete {
    background-color: var(--color-error) !important;
}
.active.organisationDetails-actionsDelete button {
    color: #EBEBEB !important;
}

.OrganisationDetail-clickable {
    cursor: pointer !important;
}

.buttonLinkContainer{
	display: flex;
    align-items: center;
}

.float-right {
    float: right;
}
</style>
