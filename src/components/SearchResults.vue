/** * SearchResults.vue * Reusable component for displaying search results *
@category Components * @package opencatalogi * @author Ruben Linde * @copyright 2024
* @license EUPL-1.2 * @version 1.0.0 * @link
https://github.com/opencatalogi/opencatalogi * * @spec openspec/specs/search/spec.md
*/

<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	NcActionButton,
	NcEmptyContent,
	NcListItem,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import { computed, onMounted, ref } from 'vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import { objectStore } from '../store/store.js'

/**
 * Props for the SearchResults component
 *
 * @typedef {object} Props
 * @property {string} [containerClass] - Additional CSS class for the container
 * @property {string} [searchClass] - Additional CSS class for the search field
 * @property {string} [resultsClass] - Additional CSS class for the results container
 */

defineProps({
	containerClass: {
		type: String,
		default: '',
	},
	searchClass: {
		type: String,
		default: '',
	},
	resultsClass: {
		type: String,
		default: '',
	},
})

/**
 * Loading state for the component
 *
 * @type {import('vue').Ref<boolean>}
 */
const loading = ref(false)

/**
 * Fetch search data from the store
 *
 * @return {Promise<void>}
 */
async function fetchData() {
	loading.value = true
	try {
		await objectStore.fetchCollection('search')
	} finally {
		loading.value = false
	}
}

/**
 * Get all search results from the store
 *
 * @return {Array<object>}
 */
const results = computed(() => objectStore.getCollection('search').results)

/**
 * Check if there are any results
 *
 * @return {boolean}
 */
const hasResults = computed(() => results.value.length > 0)

// Fetch data when component is mounted
onMounted(() => {
	fetchData()
})
</script>

<template>
	<div class="search-results" :class="[containerClass]">
		<div class="search-results__header">
			<NcTextField
				class="search-results__search"
				:class="[searchClass]"
				:modelValue="objectStore.getSearchTerm('search')"
				:label="t('opencatalogi', 'Search')"
				trailingButtonIcon="close"
				:trailingButtonLabel="t('opencatalogi', 'Clear search')"
				:showTrailingButton="objectStore.getSearchTerm('search') !== ''"
				@update:modelValue="
					(value) => objectStore.setSearchTerm('search', value)
				"
				@trailingButtonClick="objectStore.clearSearchTerm('search')">
				<template #icon>
					<MagnifyIcon />
				</template>
			</NcTextField>
			<div class="search-results__actions">
				<NcActionButton closeAfterClick @click="fetchData">
					<template #icon>
						<RefreshIcon />
					</template>
					{{ t('opencatalogi', 'Refresh') }}
				</NcActionButton>
			</div>
		</div>
		<NcLoadingIcon v-if="loading" :size="20" />
		<NcEmptyContent
			v-else-if="!hasResults"
			:name="t('opencatalogi', 'No results found')">
			<template #icon>
				<FolderIcon />
			</template>
		</NcEmptyContent>
		<div v-else class="search-results__list" :class="[resultsClass]">
			<NcListItem
				v-for="result in results"
				:key="result.id"
				:name="result.title"
				:to="'/' + result.type + 's/' + result.id">
				<template #icon>
					<FileIcon />
				</template>
				<!-- v9 dropped the `subtitle` prop; the second line is the `subname` slot. -->
				<template #subname>
					{{ result.summary }}
				</template>
			</NcListItem>
		</div>
	</div>
</template>

<style scoped>
.search-results {
	padding: 20px;
}

.search-results__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.search-results__search {
	max-width: 300px;
}

.search-results__actions {
	display: flex;
	gap: 10px;
}

.search-results__list {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
</style>
