<script setup>
import { ref, computed, onMounted, watch } from 'vue'
// NOTE: Using component instance $router/$route in watchers below
import { useSearchStore } from '../../store/modules/search.ts'
import { t } from '@nextcloud/l10n'
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcButton,
	NcSelect,
	NcNoteCard,
} from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
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
const searchTerm = ref('')
const searchTimeout = ref(null)

// Computed properties
const sidebarOpen = computed({
	get: () => props.open,
	set: (value) => emit('update:open', value),
})

const currentSortOption = computed(() => {
	const ordering = searchStore.getOrdering
	const firstOrder = Object.entries(ordering)[0]

	if (!firstOrder) return null

	const [field, direction] = firstOrder
	return sortOptions.value.find(option =>
		option.field === field && option.direction === direction,
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

const getFieldDisplayName = (fieldName) => {
	// Format field names nicely for display
	return fieldName
		.replace(/[@._]/g, ' ')
		.replace(/\b\w/g, l => l.toUpperCase())
		.trim()
}

const getFacetOptions = (facetResult) => {
	// Convert facet buckets to dropdown options
	if (!facetResult || (!facetResult.buckets && !Array.isArray(facetResult))) {
		return []
	}

	const buckets = facetResult.buckets || facetResult
	return buckets.map(bucket => ({
		value: bucket._id || bucket.key || bucket.value,
		label: `${bucket._id || bucket.key || bucket.value} (${bucket.count || bucket.results || bucket.doc_count || 0})`,
	}))
}

const getSelectedFilterValue = (fieldName) => {
	// Get the currently selected filter value for this field
	const filterKey = fieldName.startsWith('@self.') ? fieldName : fieldName
	const currentValue = searchStore.getFilters[filterKey]

	if (!currentValue) return null

	// Find the option that matches the current filter value
	const facetResult = searchStore.currentFacets[fieldName]
	const options = getFacetOptions(facetResult)
	const selectedOption = options.find(option => option.value === currentValue) || null

	return selectedOption
}

const handleFilterSelect = (fieldName, option) => {
	// Handle filter selection from dropdown
	const filterKey = fieldName.startsWith('@self.') ? fieldName : fieldName

	if (option && option.value) {
		// Set the selected filter
		searchStore.setFilters({ [filterKey]: option.value })
	} else {
		// Clear the filter if option is null (clearable)
		searchStore.clearFilter(filterKey)
	}

	// Trigger search with new filter
	searchStore.searchPublications()
}

// Watchers
watch(() => searchStore.getSearchTerm, (newTerm) => {
	if (newTerm !== searchTerm.value) {
		searchTerm.value = typeof newTerm === 'string' ? newTerm : String(newTerm || '')
	}
})

// Watch for changes to searchTerm and debounce the search
watch(searchTerm, (newValue) => {
	// Ensure we have a string value, not an event object
	const searchValue = typeof newValue === 'string' ? newValue : String(newValue || '')

	// Debounce search input
	if (searchTimeout.value) {
		clearTimeout(searchTimeout.value)
	}

	searchTimeout.value = setTimeout(() => {
		searchStore.setSearchTerm(searchValue)
		searchStore.searchPublications()
	}, 800)
})

// --- SPOT utilities ---
const RESERVED = new Set(['q', 'sort', 'view'])
const debounceTimer = ref(null)

function buildQueryFromState() {
	const q = {}
	if (searchStore.getSearchTerm) q.q = searchStore.getSearchTerm
	const ord = Object.entries(searchStore.getOrdering)[0]
	if (ord) q.sort = `${ord[0]}:${ord[1]}`
	if (searchStore.getViewMode) q.view = searchStore.getViewMode
	Object.entries(searchStore.getFilters).forEach(([k, v]) => {
		q[k] = Array.isArray(v) ? v.join(',') : String(v)
	})
	return q
}

function applyQueryToState(routeQuery) {
	const q = routeQuery || {}
	searchStore.setSearchTerm(typeof q.q === 'string' ? q.q : '')
	if (q.sort) {
		const [f, d] = String(q.sort).split(':')
		searchStore.clearOrdering()
		if (f && d) searchStore.setOrdering(f, d === 'DESC' ? 'DESC' : 'ASC')
	}
	if (q.view === 'cards' || q.view === 'table') searchStore.setViewMode(q.view)
	const filters = {}
	Object.entries(q).forEach(([k, v]) => {
		if (!RESERVED.has(k) && typeof v !== 'undefined' && v !== null && v !== '') filters[k] = String(v)
	})
	searchStore.clearAllFilters()
	if (Object.keys(filters).length) searchStore.setFilters(filters)
}

function shallowEqualQuery(a, b) {
	const ak = Object.keys(a || {})
	const bk = Object.keys(b || {})
	if (ak.length !== bk.length) return false
	for (const k of ak) {
		if (String(a[k]) !== String(b[k])) return false
	}
	return true
}

function writeUrlFromStateIfChanged(vm) {
	if (vm.$route.path !== '/search') return
	const nextQuery = buildQueryFromState()
	if (shallowEqualQuery(nextQuery, vm.$route.query)) return
	vm.$router.replace({ path: vm.$route.path, query: nextQuery })
}

// Lifecycle
onMounted(async function() {
	// Initialize from URL -> store
	applyQueryToState(this.$route.query || {})

	// Initialize search term local mirror
	searchTerm.value = searchStore.getSearchTerm

	try {
		await searchStore.loadInitialResults()
	} catch (error) {
		console.error('SearchSideBar: Failed to load initial results:', error)
	}
})

// Watch route changes -> apply to state
watch(() => this && this.$route && this.$route.fullPath, function() {
	if (!this || !this.$route) return
	if (this.$route.path !== '/search') return
	applyQueryToState(this.$route.query || {})
}.bind(this))

// Watch state changes -> write to URL (debounced)
watch([
	() => searchStore.getSearchTerm,
	() => searchStore.getViewMode,
	() => searchStore.getOrdering,
	() => searchStore.getFilters,
], function() {
	if (debounceTimer.value) clearTimeout(debounceTimer.value)
	debounceTimer.value = setTimeout(() => {
		writeUrlFromStateIfChanged(this)
	}, 400)
}.bind(this))
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
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
						:aria-label="t('opencatalogi', 'Search publications')">

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

				<!-- Filter Results Section -->
				<div v-if="searchStore.hasFacetResults && Object.keys(searchStore.getActiveFacets).length > 0" class="filter-results-section">
					<h4>{{ t('opencatalogi', 'Filter Results') }}</h4>

					<div class="filter-results-list">
						<div v-for="(facetResult, fieldName) in searchStore.currentFacets"
							:key="`filter-${fieldName}`"
							class="filter-result-item">
							<label>{{ getFieldDisplayName(fieldName.replace('@self.', '').replace('@self', 'metadata')) }}</label>
							<NcSelect
								:value="getSelectedFilterValue(fieldName)"
								:options="getFacetOptions(facetResult)"
								label="label"
								:placeholder="t('opencatalogi', 'Select a filter...')"
								:clearable="true"
								@option:selected="(option) => handleFilterSelect(fieldName, option)"
								@option:deselected="() => handleFilterSelect(fieldName, null)" />
						</div>
					</div>
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

				<!-- Faceted Filtering Section -->
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
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<style scoped>
.search-section {
	padding: 16px 0;
}

.search-section h3,
.facets-section h3 {
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.filter-results-section {
	margin: 20px 0;
	padding: 16px 0;
	border-top: 1px solid var(--color-border);
}

.filter-results-section h4 {
	margin: 0 0 16px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.filter-results-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.filter-result-item {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.filter-result-item label {
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
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

.facets-section {
	border-top: 1px solid var(--color-border);
	margin-top: 20px;
	padding-top: 20px;
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

/* Responsive adjustments */
@media (max-width: 768px) {
	.search-section,
	.facets-section {
		padding: 12px 0;
	}

	.search-section h3,
	.facets-section h3 {
		font-size: 14px;
	}

	.view-mode-toggle {
		flex-direction: column;
	}
}
</style>
