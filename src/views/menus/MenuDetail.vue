<script setup>
import { navigationStore, pageStore } from '../../store/store.js'
import { getTheme } from '../../services/getTheme.js'

</script>

<template>
	<div class="detailContainer">
		<div class="head">
			<h1 class="h1">
				{{ page.name }}
			</h1>

			<NcActions
				:disabled="loading"
				:primary="true"
				:menu-name="loading ? 'Laden...' : 'Acties'"
				:inline="1"
				title="Acties die je kan uitvoeren op deze pagina">
				<template #icon>
					<span>
						<NcLoadingIcon v-if="loading"
							:size="20"
							appearance="dark" />
						<DotsHorizontal v-if="!loading" :size="20" />
					</span>
				</template>
				<NcActionButton
					title="Bekijk de documentatie over paginas"
					@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/paginas')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					Help
				</NcActionButton>
				<NcActionButton @click="pageStore.setPageItem(page); navigationStore.setModal('editPage')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Bewerken
				</NcActionButton>
				<NcActionButton @click="pageStore.setPageItem(page); navigationStore.setDialog('copyPage')">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					Kopiëren
				</NcActionButton>
				<NcActionButton @click="pageStore.setPageItem(page); navigationStore.setDialog('deletePage')">
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
					<b>Name:</b>
					<span>{{ page.name }}</span>
				</div>
				<div>
					<b>Slug:</b>
					<span>{{ page.slug }}</span>
				</div>
				<div>
					<b>Laatst bijgewerkt:</b>
					<span>{{ page.updatedAt }}</span>
				</div>
			</div>
		</div>
		<div class="tabContainer">
			<BTabs content-class="mt-3" justified>
				<BTab title="Data" active>
					<div :class="`codeMirrorContainer ${getTheme()}`">
						<CodeMirror
							v-model="page.contents"
							:basic="true"
							:dark="getTheme() === 'dark'"
							:readonly="true"
							:lang="json()" />
					</div>
				</BTab>
			</BTabs>
		</div>
	</div>
</template>

<script>
// Components
import { NcActionButton, NcActions, NcLoadingIcon } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import CodeMirror from 'vue-codemirror6'
import { json } from '@codemirror/lang-json'

// Icons
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

/**
 * Component for displaying and managing page details
 */
export default {
	name: 'PageDetail',
	components: {
		// Components
		NcLoadingIcon,
		NcActionButton,
		NcActions,
		CodeMirror,
		// Bootstrap
		BTabs,
		BTab,
		// Icons
		DotsHorizontal,
		Pencil,
		Delete,
		ContentCopy,
		HelpCircleOutline,
	},
	props: {
		pageItem: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			page: [],
			loading: false,
			upToDate: false,
		}
	},
	watch: {
		pageItem: {
			handler(newPageItem, oldPageItem) {
				// Prevent infinite loop by checking if data is already up to date
				if (!this.upToDate || JSON.stringify(newPageItem) !== JSON.stringify(oldPageItem)) {
					this.page = newPageItem
					// Fetch new data only if we have a valid page ID
					newPageItem && this.fetchData(newPageItem?.id)
					this.upToDate = true
				}
			},
			deep: true,
		},
	},
	mounted() {
		this.page = {
			...pageStore.pageItem,
			contents: JSON.stringify(JSON.parse(pageStore.pageItem.contents), null, 2),
		}
		pageStore.pageItem && this.fetchData(pageStore.pageItem.id)
	},
	methods: {
		fetchData(id) {
			fetch(`/index.php/apps/opencatalogi/api/pages/${id}`, {
				method: 'GET',
			})
				.then((response) => {
					response.json().then((data) => {
						this.page = {
							...data,
							contents: JSON.stringify(JSON.parse(data.contents), null, 2),
						}
					})
				})
				.catch((err) => {
					console.error(err)
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

.active.pageDetails-actionsDelete {
    background-color: var(--color-error) !important;
}
.active.pageDetails-actionsDelete button {
    color: #EBEBEB !important;
}

.PageDetail-clickable {
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
