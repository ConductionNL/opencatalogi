<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useSearchStore } from '../../store/modules/search.ts'
import { navigationStore } from '../../store/store.js'
import { t } from '@nextcloud/l10n'
import { 
	NcAppSidebar, 
	NcAppSidebarTab, 
	NcTextField, 
	NcButton, 
	NcSelect, 
	NcNoteCard 
} from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ViewGrid from 'vue-material-design-icons/ViewGrid.vue'
import FormatListBulletedSquare from 'vue-material-design-icons/FormatListBulletedSquare.vue'
import FacetComponent from '../../components/FacetComponent.vue'

// Define props for sidebar control
const props = defineProps({
	open: {
		type: Boolean,
		default: false,
	},
})

// Define emits
const emit = defineEmits(['update:open'])

// Store
const searchStore = useSearchStore()

// Local state
const activeTab = ref('search-tab')
const searchTerm = ref('')
const searchTimeout = ref(null)

// Computed properties
const sidebarOpen = computed({
	get: () => props.open,
	set: (value) => emit('update:open', value)
})

const currentSortOption = computed(() => {
	const ordering = searchStore.getOrdering
	const firstOrder = Object.entries(ordering)[0]
	
	if (!firstOrder) return null
	
	const [field, direction] = firstOrder
	return sortOptions.value.find(option => 
		option.field === field && option.direction === direction
	)
})

const sortOptions = computed(() => [
	{ value: 'relevance', label: t('opencatalogi', 'Relevance'), field: null, direction: null },
	{ value: 'title-asc', label: t('opencatalogi', 'Title A-Z'), field: 'title', direction: 'ASC' },
	{ value: 'title-desc', label: t('opencatalogi', 'Title Z-A'), field: 'title', direction: 'DESC' },
	{ value: 'modified-desc', label: t('opencatalogi', 'Recently Modified'), field: '@self.updated', direction: 'DESC' },
	{ value: 'modified-asc', label: t('opencatalogi', 'Oldest First'), field: '@self.updated', direction: 'ASC' },
	{ value: 'created-desc', label: t('opencatalogi', 'Recently Created'), field: '@self.created', direction: 'DESC' },
	{ value: 'created-asc', label: t('opencatalogi', 'Oldest Created'), field: '@self.created', direction: 'ASC' },
])

const hasActiveFilters = computed(() => {
	return Object.keys(searchStore.getFilters).length > 0
})

// Directory information is now handled via facets instead of separate sources

// Methods
const updateSidebarOpen = (open) => {
	emit('update:open', open)
}

// Search input is now handled by the watcher above

const clearSearch = () => {
	searchTerm.value = ''
	searchStore.setSearchTerm('')
	searchStore.searchPublications()
}

const handleSortChange = (option) => {
	// Clear existing ordering
	searchStore.clearOrdering()
	
	if (option && option.field) {
		searchStore.setOrdering(option.field, option.direction)
	}
	
	// Trigger new search with updated ordering
	searchStore.searchPublications()
}

const clearAllFilters = () => {
	searchStore.clearAllFilters()
	searchStore.searchPublications()
}

const refreshFacets = async () => {
	await searchStore.discoverFacetableFields()
}

const formatFilterKey = (key) => {
	// Format filter keys for display
	if (key.startsWith('@self.')) {
		return key.replace('@self.', '').replace(/[_-]/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
	}
	return key.replace(/[_-]/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const formatFilterValue = (value) => {
	// Format filter values for display
	if (Array.isArray(value)) {
		return value.join(', ')
	}
	return String(value)
}

// Watchers
watch(() => searchStore.getSearchTerm, (newTerm) => {
	console.log('Store search term changed:', newTerm, typeof newTerm)
	if (newTerm !== searchTerm.value) {
		searchTerm.value = typeof newTerm === 'string' ? newTerm : String(newTerm || '')
	}
})

// Watch for changes to searchTerm and debounce the search
watch(searchTerm, (newValue) => {
	console.log('Local search term changed:', newValue, typeof newValue)
	// Ensure we have a string value, not an event object
	const searchValue = typeof newValue === 'string' ? newValue : String(newValue || '')
	
	// Debounce search input
	if (searchTimeout.value) {
		clearTimeout(searchTimeout.value)
	}
	
	searchTimeout.value = setTimeout(() => {
		console.log('Setting search term in store:', searchValue)
		searchStore.setSearchTerm(searchValue)
		searchStore.searchPublications()
	}, 800)
})

// Lifecycle
onMounted(async () => {
	// Initialize search term from store
	searchTerm.value = searchStore.getSearchTerm
	
	// Always load initial results and discover facets when component mounts
	try {
		console.log('SearchSideBar: Component mounted, loading initial search results...')
		console.log('SearchSideBar: isSearchPage check:', navigationStore?.selected === 'search')
		console.log('SearchSideBar: Props open:', props.open)
		await searchStore.loadInitialResults()
		console.log('SearchSideBar: Initial results loaded successfully')
		console.log('SearchSideBar: Results count:', searchStore.getSearchResults.length)
		console.log('SearchSideBar: Facetable fields:', Object.keys(searchStore.getFacetable))
	} catch (error) {
		console.error('SearchSideBar: Failed to load initial results:', error)
	}
})
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		name="Search Publications"
		subtitle="Filter and explore publications"
		subname="Across all federated catalogs"
		:open="sidebarOpen"
		@update:open="(e) => updateSidebarOpen(e)">
		
		<NcAppSidebarTab id="search-tab" name="Search" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

			<!-- Search Section -->
			<div class="search-section">
				<h3>{{ t('opencatalogi', 'Search Publications') }}</h3>
				
				<!-- Search input -->
				<div class="search-group">
					<input
						v-model="searchTerm"
						type="search"
						placeholder="Type to search publications..."
						class="search-input"
						:aria-label="t('opencatalogi', 'Search publications')" />
					
					<NcButton 
						v-if="searchTerm"
						type="tertiary"
						:aria-label="t('opencatalogi', 'Clear search')"
						@click="clearSearch">
						<template #icon>
							<Close :size="16" />
						</template>
						{{ t('opencatalogi', 'Clear') }}
					</NcButton>
				</div>

				<!-- Quick filters -->
				<div class="quick-filters">
					<h4>{{ t('opencatalogi', 'Quick Filters') }}</h4>
					
					<!-- Sort options -->
					<div class="filter-group">
						<label>{{ t('opencatalogi', 'Sort by') }}</label>
						<NcSelect
							:value="currentSortOption"
							:options="sortOptions"
							label="label"
							:label-outside="true"
							:placeholder="t('opencatalogi', 'Choose sorting')"
							@update:value="handleSortChange" />
					</div>
					
					<!-- View mode toggle -->
					<div class="filter-group">
						<label>{{ t('opencatalogi', 'View Mode') }}</label>
						<div class="view-mode-toggle">
							<NcButton 
								:type="searchStore.getViewMode === 'cards' ? 'primary' : 'tertiary'"
								:aria-label="t('opencatalogi', 'Card view')"
								@click="searchStore.setViewMode('cards')">
								<template #icon>
									<ViewGrid :size="16" />
								</template>
								{{ t('opencatalogi', 'Cards') }}
							</NcButton>
							<NcButton 
								:type="searchStore.getViewMode === 'table' ? 'primary' : 'tertiary'"
								:aria-label="t('opencatalogi', 'Table view')"
								@click="searchStore.setViewMode('table')">
								<template #icon>
									<FormatListBulletedSquare :size="16" />
								</template>
								{{ t('opencatalogi', 'Table') }}
							</NcButton>
						</div>
					</div>
				</div>

				<!-- Active filters display -->
				<div v-if="hasActiveFilters" class="active-filters">
					<h4>{{ t('opencatalogi', 'Active Filters') }}</h4>
					<div class="active-filters-list">
						<div v-for="(value, key) in searchStore.getFilters" 
							:key="`filter-${key}`"
							class="active-filter-item">
							<span class="filter-key">{{ formatFilterKey(key) }}:</span>
							<span class="filter-value">{{ formatFilterValue(value) }}</span>
							<NcButton 
								type="tertiary-no-background"
								:aria-label="t('opencatalogi', 'Remove filter')"
								@click="searchStore.clearFilter(key)">
								<template #icon>
									<Close :size="14" />
								</template>
							</NcButton>
						</div>
					</div>
					<NcButton 
						type="tertiary"
						:aria-label="t('opencatalogi', 'Clear all filters')"
						@click="clearAllFilters">
						{{ t('opencatalogi', 'Clear All Filters') }}
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="facets-tab" name="Facets" :order="2">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Facet discovery and management -->
			<div class="facets-section">
				<div class="facets-header">
					<h3>{{ t('opencatalogi', 'Faceted Filtering') }}</h3>
					<NcButton 
						type="tertiary"
						:disabled="searchStore.isFacetsLoading"
						:aria-label="t('opencatalogi', 'Refresh facets')"
						@click="refreshFacets">
						<template #icon>
							<Refresh :size="16" />
						</template>
						{{ t('opencatalogi', 'Refresh') }}
					</NcButton>
				</div>
				
				<NcNoteCard type="info" class="facets-info">
					{{ t('opencatalogi', 'Facets help you filter results by different criteria. Enable facets below to see available filter options.') }}
				</NcNoteCard>

				<!-- Facet component -->
				<FacetComponent />
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="results-tab" name="Results" :order="3">
			<template #icon>
				<FormatListBulleted :size="20" />
			</template>

			<!-- Results summary -->
			<div class="results-section">
				<h3>{{ t('opencatalogi', 'Search Results') }}</h3>
				
				<!-- Results statistics -->
				<div class="results-stats">
					<div class="stat-item">
						<strong>{{ searchStore.getPagination.total }}</strong>
						<span>{{ t('opencatalogi', 'Total Results') }}</span>
					</div>
					<div class="stat-item">
						<strong>{{ searchStore.getPagination.page }}</strong>
						<span>{{ t('opencatalogi', 'of') }} {{ searchStore.getPagination.pages }} {{ t('opencatalogi', 'pages') }}</span>
					</div>
					<div v-if="searchStore.hasFacets" class="stat-item">
						<strong>{{ Object.keys(searchStore.getFacets).length }}</strong>
						<span>{{ t('opencatalogi', 'Active Facets') }}</span>
					</div>
				</div>

				<!-- Directory filtering is now handled via facets -->

				<!-- Selection info -->
				<div v-if="searchStore.getSelectedPublications.length > 0" class="selection-info">
					<h4>{{ t('opencatalogi', 'Selected Items') }}</h4>
					<p>
						{{ t('opencatalogi', '{count} publications selected', { count: searchStore.getSelectedPublications.length }) }}
					</p>
					<NcButton 
						type="tertiary"
						:aria-label="t('opencatalogi', 'Clear selection')"
						@click="searchStore.clearAllSelections()">
						{{ t('opencatalogi', 'Clear Selection') }}
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<style scoped>
.search-section,
.facets-section,
.results-section {
	padding: 16px 0;
}

.search-section h3,
.facets-section h3,
.results-section h3 {
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.search-group {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 20px;
}

.search-input {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.quick-filters {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
	margin-bottom: 20px;
}

.quick-filters h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.filter-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.filter-group label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.view-mode-toggle {
	display: flex;
	gap: 8px;
}

.active-filters {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.active-filters h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.active-filters-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.active-filter-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	background: var(--color-background-hover);
	border-radius: 6px;
	font-size: 13px;
}

.filter-key {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.filter-value {
	flex: 1;
	color: var(--color-main-text);
}

.facets-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.facets-info {
	margin-bottom: 16px;
}

.results-stats {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	margin-bottom: 20px;
}

.stat-item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.stat-item strong {
	color: var(--color-primary);
	font-weight: 600;
}

.stat-item span {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.federation-info,
.selection-info {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
	margin-bottom: 20px;
}

.federation-info h4,
.selection-info h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.source-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.source-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 12px;
	background: var(--color-background-hover);
	border-radius: 6px;
	font-size: 12px;
}

.source-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.source-url {
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.selection-info p {
	margin: 0 0 12px 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* Responsive adjustments */
@media (max-width: 768px) {
	.search-section,
	.facets-section,
	.results-section {
		padding: 12px 0;
	}
	
	.search-section h3,
	.facets-section h3,
	.results-section h3 {
		font-size: 14px;
	}
	
	.view-mode-toggle {
		flex-direction: column;
	}
}
</style>
