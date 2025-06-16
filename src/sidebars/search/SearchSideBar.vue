<script setup>
import { useSearchStore } from '../../store/modules/search.ts'
</script>

<template>
	<div class="searchSideBar">
		<div class="searchSideBar-header">
			<h2>{{ t('opencatalogi', 'Search Filters') }}</h2>
		</div>
		<div class="searchSideBar-content">
			<!-- Search Input -->
			<div class="searchSideBar-content-item">
				<h3>{{ t('opencatalogi', 'Search Term') }}</h3>
				<NcTextField
					:value="searchStore.getSearchTerm"
					:label="t('opencatalogi', 'Search publications...')"
					trailing-button-icon="close"
					:show-trailing-button="searchStore.getSearchTerm !== ''"
					@update:value="onSearchTermChange"
					@trailing-button-click="clearSearch"
					@keydown.enter="performSearch">
					<template #leading-icon>
						<Magnify :size="20" />
					</template>
				</NcTextField>
			</div>

			<!-- Search Actions -->
			<div class="searchSideBar-content-item">
				<div class="searchActions">
					<NcButton
						type="primary"
						:disabled="searchStore.isLoading"
						@click="performSearch">
						<template #icon>
							<Magnify :size="20" />
						</template>
						{{ t('opencatalogi', 'Search') }}
					</NcButton>
					<NcButton
						type="secondary"
						@click="clearSearch">
						<template #icon>
							<Close :size="20" />
						</template>
						{{ t('opencatalogi', 'Clear') }}
					</NcButton>
				</div>
			</div>

			<!-- Dynamic Filters from Facetable Data -->
			<div v-if="availableFilters.length > 0" class="searchSideBar-content-item">
				<h3>{{ t('opencatalogi', 'Filters') }}</h3>
				<div class="searchSideBar-content-item-filters">
					<!-- System Metadata Filters -->
					<div v-for="filter in systemFilters" :key="filter.key" class="searchSideBar-content-item-filters-item">
						<h4>{{ filter.label }}</h4>
						<div v-for="option in filter.options" :key="option.value" class="filterOption">
							<NcCheckboxRadioSwitch
								:checked="searchStore.getFilters[filter.key] === option.value"
								@update:checked="(checked) => toggleFilter(filter.key, option.value, checked)">
								{{ option.label }} ({{ option.count }})
							</NcCheckboxRadioSwitch>
						</div>
					</div>

					<!-- Object Field Filters -->
					<div v-for="filter in objectFieldFilters" :key="filter.key" class="searchSideBar-content-item-filters-item">
						<h4>{{ filter.label }}</h4>
						<div v-if="filter.type === 'categorical'" class="filterOptions">
							<div v-for="option in filter.options" :key="option.value" class="filterOption">
								<NcCheckboxRadioSwitch
									:checked="searchStore.getFilters[filter.key] === option.value"
									@update:checked="(checked) => toggleFilter(filter.key, option.value, checked)">
									{{ option.value }} ({{ option.count || 'N/A' }})
								</NcCheckboxRadioSwitch>
							</div>
						</div>
						<div v-else-if="filter.type === 'boolean'" class="filterOptions">
							<NcCheckboxRadioSwitch
								:checked="searchStore.getFilters[filter.key] === 'true'"
								@update:checked="(checked) => toggleFilter(filter.key, 'true', checked)">
								{{ t('opencatalogi', 'Yes') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:checked="searchStore.getFilters[filter.key] === 'false'"
								@update:checked="(checked) => toggleFilter(filter.key, 'false', checked)">
								{{ t('opencatalogi', 'No') }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</div>
			</div>

			<!-- Legacy Facets (fallback) -->
			<div v-else-if="Object.keys(searchStore.getFacets).length > 0" class="searchSideBar-content-item">
				<h3>{{ t('opencatalogi', 'Filters') }}</h3>
				<div class="searchSideBar-content-item-filters">
					<div v-for="(facet, key) in searchStore.getFacets" :key="key" class="searchSideBar-content-item-filters-item">
						<h4>{{ key }}</h4>
						<div v-for="(count, value) in facet" :key="value" class="filterOption">
							<NcCheckboxRadioSwitch
								:checked="searchStore.getFilters[key] === value"
								@update:checked="(checked) => toggleFilter(key, value, checked)">
								{{ value }} ({{ count }})
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</div>
			</div>

			<!-- Search Statistics -->
			<div v-if="searchStore.getSearchTerm || searchStore.getPagination.total > 0" class="searchSideBar-content-item">
				<h3>{{ t('opencatalogi', 'Search Results') }}</h3>
				<div class="searchStats">
					<p v-if="searchStore.getSearchTerm">
						<strong>{{ t('opencatalogi', 'Search term:') }}</strong> "{{ searchStore.getSearchTerm }}"
					</p>
					<p v-if="searchStore.getPagination.total > 0">
						<strong>{{ t('opencatalogi', 'Results:') }}</strong> {{ searchStore.getPagination.total }} {{ t('opencatalogi', 'publications found') }}
					</p>
					<p v-if="searchStore.isLoading">
						<em>{{ t('opencatalogi', 'Searching...') }}</em>
					</p>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'SearchSideBar',
	components: {
		NcTextField,
		NcButton,
		NcCheckboxRadioSwitch,
		Magnify,
		Close,
	},
	data() {
		return {
			searchStore: useSearchStore(),
			searchTimeout: null,
		}
	},
	computed: {
		/**
		 * Get available filters from facetable data
		 */
		availableFilters() {
			const facetable = this.searchStore.getFacetable
			if (!facetable || (!facetable['@self'] && !facetable.object_fields)) {
				return []
			}
			
			const filters = []
			
			// Add system metadata filters
			if (facetable['@self']) {
				Object.entries(facetable['@self']).forEach(([key, config]) => {
					if (config.sample_values && config.sample_values.length > 0) {
						filters.push({
							key: key,
							label: this.formatFilterLabel(key),
							type: config.type,
							options: config.sample_values,
							category: 'system'
						})
					}
				})
			}
			
			// Add object field filters
			if (facetable.object_fields) {
				Object.entries(facetable.object_fields).forEach(([key, config]) => {
					if (config.sample_values && config.sample_values.length > 0 && this.shouldShowFilter(key, config)) {
						filters.push({
							key,
							label: this.formatFilterLabel(key),
							type: config.type,
							options: config.sample_values.map(value => ({ value, count: 'N/A' })),
							category: 'object'
						})
					}
				})
			}
			
			return filters
		},
		
		/**
		 * Get system metadata filters
		 */
		systemFilters() {
			return this.availableFilters.filter(f => f.category === 'system')
		},
		
		/**
		 * Get object field filters
		 */
		objectFieldFilters() {
			return this.availableFilters.filter(f => f.category === 'object')
		},
	},
	mounted() {
		console.info('SearchSideBar mounted')
		// Only perform search if there's already a search term
		// Initial results loading is handled by SearchIndex
		if (this.searchStore.getSearchTerm) {
			this.performSearch()
		}
	},
	methods: {
		onSearchTermChange(value) {
			this.searchStore.setSearchTerm(value)
			
			// Clear existing timeout
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			
			// Set new timeout for real-time search
			this.searchTimeout = setTimeout(() => {
				this.performSearch()
			}, 500) // 500ms delay for real-time search
		},
		async performSearch() {
			console.info('Performing search from sidebar with term:', this.searchStore.getSearchTerm)
			await this.searchStore.searchPublications()
		},
		clearSearch() {
			console.info('Clearing search from sidebar')
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			this.searchStore.clearSearch()
		},
		toggleFilter(key, value, checked) {
			if (checked) {
				this.searchStore.setFilters({ [key]: value })
			} else {
				this.searchStore.clearFilter(key)
			}
			// Automatically search when filters change
			this.performSearch()
		},
		
		/**
		 * Format filter label for display
		 */
		formatFilterLabel(key) {
			// Convert camelCase and snake_case to readable labels
			return key
				.replace(/([A-Z])/g, ' $1')
				.replace(/_/g, ' ')
				.replace(/\b\w/g, l => l.toUpperCase())
				.trim()
		},
		
		/**
		 * Determine if a filter should be shown
		 */
		shouldShowFilter(key, config) {
			// Skip filters with empty values or very high cardinality
			if (!config.sample_values || config.sample_values.length === 0) {
				return false
			}
			
			// Skip fields that are likely not useful for filtering
			const skipFields = ['id', 'extend', 'attachments.endpoint', 'attachments.filename']
			if (skipFields.includes(key)) {
				return false
			}
			
			// Skip fields with only empty values
			if (config.sample_values.every(val => val === '' || val === null)) {
				return false
			}
			
			// Show categorical and boolean fields
			if (config.type === 'boolean' || config.type === 'categorical') {
				return true
			}
			
			// Show string fields with low cardinality
			if (config.type === 'string' && config.cardinality === 'low') {
				return true
			}
			
			return false
		},
	},
}
</script>

<style scoped>
.searchSideBar {
	padding: 1rem;
	height: 100%;
	overflow-y: auto;
}

.searchSideBar-header {
	margin-bottom: 1rem;
}

.searchSideBar-content-item {
	margin-bottom: 1.5rem;
}

.searchSideBar-content-item-filters-item {
	margin-bottom: 1rem;
}

.searchActions {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.filterOption {
	margin-bottom: 0.5rem;
}

.filterOptions {
	max-height: 200px;
	overflow-y: auto;
}

.searchStats {
	background-color: var(--color-background-hover);
	padding: 0.75rem;
	border-radius: var(--border-radius);
	font-size: 0.9em;
}

.searchStats p {
	margin: 0.25rem 0;
}

h2 {
	margin: 0;
	font-size: 1.2em;
}

h3 {
	margin: 0 0 0.5rem 0;
	font-size: 1.1em;
	font-weight: 600;
}

h4 {
	margin: 0 0 0.5rem 0;
	font-size: 1em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}
</style>
