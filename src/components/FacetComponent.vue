<template>
	<div class="facet-component">
		<!-- Loading state for facetable discovery -->
		<div v-if="searchStore.isFacetsLoading" class="facet-loading">
			<NcLoadingIcon :size="20" />
			<span>{{ t('opencatalogi', 'Discovering facets...') }}</span>
		</div>

		<!-- No facets available state -->
		<div v-else-if="!searchStore.hasFacetableFields" class="no-facets">
			<NcNoteCard type="info">
				{{ t('opencatalogi', 'No facets available for current search') }}
			</NcNoteCard>
		</div>

		<!-- Facet management interface -->
		<div v-else class="facet-management">
			<h4 class="facet-section-title">
				{{ t('opencatalogi', 'Available Facets') }}
			</h4>
			
			<!-- Metadata facets (@self) -->
			<div v-if="Object.keys(searchStore.availableMetadataFacets).length > 0" class="facet-category">
				<h5 class="facet-category-title">
					{{ t('opencatalogi', 'Metadata Facets') }}
				</h5>
				<div class="facet-controls">
					<div 
						v-for="(fieldInfo, fieldName) in searchStore.availableMetadataFacets"
						:key="`meta-${fieldName}`"
						class="facet-control">
						<NcCheckboxRadioSwitch
							:checked="isActiveFacet(`@self.${fieldName}`)"
							:title="fieldInfo.description || `Filter by ${fieldName}`"
							@update:checked="(enabled) => toggleFacet(`@self.${fieldName}`, fieldInfo, enabled)">
							{{ getFieldDisplayName(fieldName, fieldInfo) }}
							<span v-if="fieldInfo.has_labels" class="facet-badge">{{ t('opencatalogi', 'with labels') }}</span>
						</NcCheckboxRadioSwitch>
						
						<!-- Facet type selection for multi-type fields -->
						<div v-if="isActiveFacet(`@self.${fieldName}`) && fieldInfo.facet_types && fieldInfo.facet_types.length > 1" 
							class="facet-type-selector">
							<NcSelect
								:value="getActiveFacetType(`@self.${fieldName}`)"
								:options="getFacetTypeOptions(fieldInfo.facet_types)"
								label="label"
								:placeholder="t('opencatalogi', 'Select facet type')"
								@update:value="(option) => updateFacetType(`@self.${fieldName}`, option.value, fieldInfo)" />
						</div>
						
						<!-- Date histogram interval selection -->
						<div v-if="isActiveFacet(`@self.${fieldName}`) && getActiveFacetType(`@self.${fieldName}`) === 'date_histogram'" 
							class="facet-config">
							<NcSelect
								:value="getActiveFacetInterval(`@self.${fieldName}`)"
								:options="getIntervalOptions(fieldInfo.intervals)"
								label="label"
								:placeholder="t('opencatalogi', 'Select interval')"
								@update:value="(option) => updateFacetInterval(`@self.${fieldName}`, option.value)" />
						</div>
					</div>
				</div>
			</div>

			<!-- Object field facets -->
			<div v-if="Object.keys(searchStore.availableObjectFieldFacets).length > 0" class="facet-category">
				<h5 class="facet-category-title">
					{{ t('opencatalogi', 'Content Facets') }}
				</h5>
				<div class="facet-controls">
					<div 
						v-for="(fieldInfo, fieldName) in searchStore.availableObjectFieldFacets"
						:key="`obj-${fieldName}`"
						class="facet-control">
						<NcCheckboxRadioSwitch
							:checked="isActiveFacet(fieldName)"
							:title="fieldInfo.description || `Filter by ${fieldName}`"
							@update:checked="(enabled) => toggleFacet(fieldName, fieldInfo, enabled)">
							{{ getFieldDisplayName(fieldName, fieldInfo) }}
							<span class="facet-info">
								({{ fieldInfo.appearance_rate }} {{ t('opencatalogi', 'items') }})
							</span>
						</NcCheckboxRadioSwitch>
						
						<!-- Facet type selection for multi-type fields -->
						<div v-if="isActiveFacet(fieldName) && fieldInfo.facet_types && fieldInfo.facet_types.length > 1" 
							class="facet-type-selector">
							<NcSelect
								:value="getActiveFacetType(fieldName)"
								:options="getFacetTypeOptions(fieldInfo.facet_types)"
								label="label"
								:placeholder="t('opencatalogi', 'Select facet type')"
								@update:value="(option) => updateFacetType(fieldName, option.value, fieldInfo)" />
						</div>
					</div>
				</div>
			</div>

			<!-- Active facets summary -->
			<div v-if="searchStore.hasActiveFacets" class="active-facets-summary">
				<h5 class="facet-category-title">
					{{ t('opencatalogi', 'Active Facets') }}
					<NcButton 
						type="tertiary"
						:aria-label="t('opencatalogi', 'Clear all facets')"
						@click="clearAllFacets">
						<template #icon>
							<Close :size="16" />
						</template>
						{{ t('opencatalogi', 'Clear All') }}
					</NcButton>
				</h5>
				<div class="active-facets-list">
					<div v-for="(facetConfig, fieldName) in searchStore.getActiveFacets" 
						:key="`active-${fieldName}`"
						class="active-facet-item">
						<span class="active-facet-name">{{ getFieldDisplayName(fieldName.replace('@self.', ''), {}) }}</span>
						<span class="active-facet-type">({{ facetConfig.type }})</span>
						<NcButton 
							type="tertiary-no-background"
							:aria-label="t('opencatalogi', 'Remove facet')"
							@click="removeFacet(fieldName)">
							<template #icon>
								<Close :size="14" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- Facet results display -->
		<div v-if="searchStore.hasFacets && searchStore.hasActiveFacets" class="facet-results">
			<h4 class="facet-section-title">
				{{ t('opencatalogi', 'Filter Results') }}
			</h4>
			
			<!-- Metadata facet results -->
			<div v-if="searchStore.currentFacets['@self']" class="facet-results-category">
				<div v-for="(facetResult, fieldName) in searchStore.currentFacets['@self']" 
					:key="`result-meta-${fieldName}`"
					class="facet-result">
					<h6 class="facet-result-title">
						{{ getFieldDisplayName(fieldName, {}) }}
					</h6>
					<div class="facet-buckets">
						<div v-for="bucket in facetResult.buckets" 
							:key="`bucket-${fieldName}-${bucket.key}`"
							class="facet-bucket">
							<NcCheckboxRadioSwitch
								:checked="isFilterActive(`@self.${fieldName}`, bucket.key)"
								@update:checked="(checked) => toggleFilter(`@self.${fieldName}`, bucket.key, checked)">
								{{ bucket.key }}
								<span class="facet-count">({{ bucket.results || bucket.doc_count || 0 }})</span>
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</div>
			</div>

			<!-- Object field facet results -->
			<div class="facet-results-category">
				<div v-for="(facetResult, fieldName) in searchStore.currentFacets" 
					:key="`result-obj-${fieldName}`"
					class="facet-result">
					<template v-if="fieldName !== '@self'">
						<h6 class="facet-result-title">
							{{ getFieldDisplayName(fieldName, {}) }}
						</h6>
						<div class="facet-buckets">
							<div v-for="bucket in facetResult.buckets" 
								:key="`bucket-${fieldName}-${bucket.key}`"
								class="facet-bucket">
								<NcCheckboxRadioSwitch
									:checked="isFilterActive(fieldName, bucket.key)"
									@update:checked="(checked) => toggleFilter(fieldName, bucket.key, checked)">
									{{ bucket.key }}
									<span class="facet-count">({{ bucket.results || bucket.doc_count || 0 }})</span>
								</NcCheckboxRadioSwitch>
							</div>
						</div>
					</template>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { useSearchStore } from '../store/modules/search.ts'
import { t } from '@nextcloud/l10n'
import { 
	NcCheckboxRadioSwitch, 
	NcButton, 
	NcSelect, 
	NcNoteCard, 
	NcLoadingIcon 
} from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'

// Store
const searchStore = useSearchStore()

// Methods
const isActiveFacet = (fieldName) => {
	return searchStore.getActiveFacets.hasOwnProperty(fieldName)
}

const getActiveFacetType = (fieldName) => {
	const facetConfig = searchStore.getActiveFacets[fieldName]
	return facetConfig ? facetConfig.type : null
}

const getActiveFacetInterval = (fieldName) => {
	const facetConfig = searchStore.getActiveFacets[fieldName]
	return facetConfig?.config?.interval || 'month'
}

const toggleFacet = (fieldName, fieldInfo, enabled) => {
	if (enabled) {
		// Determine default facet type
		const defaultType = fieldInfo.facet_types?.[0] || 'terms'
		const config = {}
		
		// Add default configuration based on type
		if (defaultType === 'date_histogram') {
			config.interval = fieldInfo.intervals?.[0] || 'month'
		}
		
		searchStore.toggleActiveFacet(fieldName, defaultType, true, config)
	} else {
		searchStore.toggleActiveFacet(fieldName, '', false)
	}
	
	// Trigger new search with updated facets
	searchStore.searchPublications()
}

const updateFacetType = (fieldName, newType, fieldInfo) => {
	const config = {}
	
	// Add type-specific configuration
	if (newType === 'date_histogram') {
		config.interval = fieldInfo.intervals?.[0] || 'month'
	}
	
	searchStore.toggleActiveFacet(fieldName, newType, true, config)
	
	// Trigger new search with updated facets
	searchStore.searchPublications()
}

const updateFacetInterval = (fieldName, interval) => {
	const currentConfig = searchStore.getActiveFacets[fieldName]
	if (currentConfig) {
		const newConfig = { ...currentConfig.config, interval }
		searchStore.toggleActiveFacet(fieldName, currentConfig.type, true, newConfig)
		
		// Trigger new search with updated facets
		searchStore.searchPublications()
	}
}

const removeFacet = (fieldName) => {
	searchStore.toggleActiveFacet(fieldName, '', false)
	
	// Trigger new search without this facet
	searchStore.searchPublications()
}

const clearAllFacets = () => {
	searchStore.clearAllActiveFacets()
	
	// Trigger new search without facets
	searchStore.searchPublications()
}

const getFacetTypeOptions = (facetTypes) => {
	return facetTypes.map(type => ({
		value: type,
		label: type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
	}))
}

const getIntervalOptions = (intervals) => {
	const defaultIntervals = ['day', 'week', 'month', 'year']
	const availableIntervals = intervals || defaultIntervals
	
	return availableIntervals.map(interval => ({
		value: interval,
		label: interval.charAt(0).toUpperCase() + interval.slice(1)
	}))
}

const getFieldDisplayName = (fieldName, fieldInfo) => {
	// Use description if available, otherwise format field name
	if (fieldInfo.description) {
		return fieldInfo.description
	}
	
	// Format field names nicely
	return fieldName
		.replace(/[@._]/g, ' ')
		.replace(/\b\w/g, l => l.toUpperCase())
		.trim()
}

const isFilterActive = (fieldName, value) => {
	// Check if this specific filter value is active
	const filterKey = fieldName.startsWith('@self.') ? fieldName : fieldName
	return searchStore.getFilters[filterKey] === value || 
		   (Array.isArray(searchStore.getFilters[filterKey]) && searchStore.getFilters[filterKey].includes(value))
}

const toggleFilter = (fieldName, value, checked) => {
	const filterKey = fieldName.startsWith('@self.') ? fieldName : fieldName
	
	if (checked) {
		// Add filter
		const currentValue = searchStore.getFilters[filterKey]
		if (currentValue === undefined) {
			searchStore.setFilters({ [filterKey]: value })
		} else if (Array.isArray(currentValue)) {
			if (!currentValue.includes(value)) {
				searchStore.setFilters({ [filterKey]: [...currentValue, value] })
			}
		} else {
			// Convert single value to array
			searchStore.setFilters({ [filterKey]: [currentValue, value] })
		}
	} else {
		// Remove filter
		const currentValue = searchStore.getFilters[filterKey]
		if (Array.isArray(currentValue)) {
			const newValue = currentValue.filter(v => v !== value)
			if (newValue.length === 0) {
				searchStore.clearFilter(filterKey)
			} else if (newValue.length === 1) {
				searchStore.setFilters({ [filterKey]: newValue[0] })
			} else {
				searchStore.setFilters({ [filterKey]: newValue })
			}
		} else if (currentValue === value) {
			searchStore.clearFilter(filterKey)
		}
	}
	
	// Trigger new search with updated filters
	searchStore.searchPublications()
}
</script>

<style scoped>
.facet-component {
	padding: 16px 0;
}

.facet-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px;
	color: var(--color-text-maxcontrast);
}

.no-facets {
	padding: 16px 0;
}

.facet-section-title {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 16px 0;
	color: var(--color-main-text);
}

.facet-category {
	margin-bottom: 24px;
}

.facet-category-title {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.facet-controls {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.facet-control {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.facet-badge {
	font-size: 11px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	padding: 2px 6px;
	border-radius: 10px;
	margin-left: 8px;
}

.facet-info {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-left: 8px;
}

.facet-type-selector,
.facet-config {
	margin-left: 24px;
	margin-top: 8px;
}

.active-facets-summary {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.active-facets-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.active-facet-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	background: var(--color-background-hover);
	border-radius: 6px;
	font-size: 13px;
}

.active-facet-name {
	font-weight: 500;
}

.active-facet-type {
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.facet-results {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.facet-results-category {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.facet-result {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.facet-result-title {
	font-size: 13px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.facet-buckets {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-left: 12px;
}

.facet-bucket {
	display: flex;
	align-items: center;
}

.facet-count {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-left: 6px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
	.facet-component {
		padding: 12px 0;
	}
	
	.facet-section-title {
		font-size: 14px;
	}
	
	.facet-category-title {
		font-size: 13px;
	}
}
</style> 