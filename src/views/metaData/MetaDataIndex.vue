<script setup>
import { navigationStore, searchStore, metadataStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<MetaDataList :search="searchStore.search" />
		</template>
		<template #default>
			<NcEmptyContent v-if="!metadataStore.metaDataItem || navigationStore.selected != 'metaData'"
				class="detailContainer"
				name="Geen publicatietype"
				description="Nog geen publicatietype geselecteerd">
				<template #icon>
					<FileTreeOutline />
				</template>
				<template #action>
					<NcButton type="primary" @click="navigationStore.setModal('addMetaData')">
						Publicatietype toevoegen
					</NcButton>
				</template>
			</NcEmptyContent>
			<MetaDataDetails v-if="metadataStore.metaDataItem && navigationStore.selected === 'metaData'" :meta-data-item="metadataStore.metaDataItem" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import MetaDataList from './MetaDataList.vue'
import MetaDataDetails from './MetaDataDetail.vue'
// eslint-disable-next-line n/no-missing-import
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline'

export default {
	name: 'MetaDataIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		MetaDataList,
		MetaDataDetails,
		FileTreeOutline,
	},
	data() {
		return {

		}
	},
}
</script>
