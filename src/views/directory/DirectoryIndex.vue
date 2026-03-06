<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('opencatalogi', 'Directory') }}
				</h1>
				<p>{{ t('opencatalogi', 'Browse and manage directory listings from various catalogs') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('opencatalogi', 'Showing {showing} of {total} listings', { showing: filteredListings.length, total: currentPagination.total || filteredListings.length }) }}
					</span>
					<span v-if="selectedListings.length > 0" class="viewIndicator">
						({{ t('opencatalogi', '{count} selected', { count: selectedListings.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-tooltip="'See listings as cards'"
							:checked="viewMode === 'cards'"
							:button-variant="true"
							:class="{ 'checkbox-radio-switch--checked': viewMode === 'cards' }"
							value="cards"
							name="listings_view_mode"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="() => setViewMode('cards')">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-tooltip="'See listings as a table'"
							:checked="viewMode === 'table'"
							:button-variant="true"
							:class="{ 'checkbox-radio-switch--checked': viewMode === 'table' }"
							value="table"
							name="listings_view_mode"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="() => setViewMode('table')">
							Table
						</NcCheckboxRadioSwitch>
					</div>

					<NcActions
						:force-name="true"
						:inline="3"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="navigationStore.setModal('addDirectory')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Directory
						</NcActionButton>
						<NcActionButton
							close-after-click
							:disabled="objectStore.isLoading('listing')"
							@click="objectStore.fetchCollection('listing')">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
						<NcActionButton
							title="View documentation about directory"
							@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/directory', '_blank')">
							<template #icon>
								<HelpCircleOutline :size="20" />
							</template>
							Help
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="objectStore.isLoading('listing') || !filteredListings.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.isLoading('listing')" :size="64" />
					<LayersOutline v-else :size="64" />
				</template>
				<template v-if="!objectStore.isLoading('listing') && !objectStore.getCollection('listing')?.results?.length" #action>
					<NcButton type="primary" @click="navigationStore.setModal('addDirectory')">
						{{ t('opencatalogi', 'Add directory') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="listing in paginatedListings" :key="listing.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="listing.summary">
									<component :is="getStatusIcon(listing)"
										:size="20"
										:class="getStatusClass(listing)" />
									{{ listing.name || listing.title }}
									<span v-if="listing.default" class="defaultBadge">
										<Star :size="14" />
										Default
									</span>
									<span v-if="!listing.available" class="errorBadge">
										<CloseCircle :size="14" />
										Disabled
									</span>
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="refreshDirectory(listing)">
										<template #icon>
											<Refresh :size="20" />
										</template>
										Sync Directory
									</NcActionButton>
									<NcActionButton close-after-click @click="toggleIntegrationLevel(listing)">
										<template #icon>
											<component :is="listing.integrationLevel === 'search' ? 'CloseCircle' : 'CheckCircle'" :size="20" />
										</template>
										{{ listing.integrationLevel === 'search' ? 'Disable' : 'Enable' }}
									</NcActionButton>
									<NcActionButton close-after-click @click="toggleDefault(listing)">
										<template #icon>
											<component :is="listing.default ? 'Star' : 'StarOutline'" :size="20" />
										</template>
										{{ listing.default ? 'Remove as Default' : 'Set as Default' }}
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Listing Statistics Table -->
							<table class="statisticsTable listingStats">
								<thead>
									<tr>
										<th>{{ t('opencatalogi', 'Property') }}</th>
										<th>{{ t('opencatalogi', 'Value') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-if="listing.organization">
										<td>{{ t('opencatalogi', 'Organization') }}</td>
										<td>{{ listing.organization.title || listing.organization }}</td>
									</tr>
									<tr v-if="listing.summary">
										<td>{{ t('opencatalogi', 'Summary') }}</td>
										<td class="truncatedText">
											{{ listing.summary }}
										</td>
									</tr>
									<tr>
										<td>{{ t('opencatalogi', 'Integration Level') }}</td>
										<td>{{ _.upperFirst(listing.integrationLevel) || '-' }}</td>
									</tr>
									<tr>
										<td>{{ t('opencatalogi', 'Version') }}</td>
										<td>{{ listing.version || '-' }}</td>
									</tr>
									<tr>
										<td>{{ t('opencatalogi', 'Schemas') }}</td>
										<td>{{ listing.schemaCount || listing.schemas?.length || 0 }}</td>
									</tr>
									<tr>
										<td>{{ t('opencatalogi', 'Last Sync') }}</td>
										<td>{{ formatDate(listing.lastSync) }}</td>
									</tr>
									<tr>
										<td>{{ t('opencatalogi', 'Available') }}</td>
										<td :class="listing.available ? 'status-success' : 'status-error'">
											{{ listing.available ? 'Yes' : 'No' }}
										</td>
									</tr>
									<tr>
										<td>{{ t('opencatalogi', 'Status') }}</td>
										<td>{{ getStatusText(listing) }}</td>
									</tr>
									<tr>
										<td>{{ t('opencatalogi', 'Default Directory') }}</td>
										<td :class="listing.default ? 'status-success' : (listing.available ? '' : 'status-error')">
											{{ listing.default ? 'Yes' : (listing.available ? 'No' : 'Disabled') }}
										</td>
									</tr>
									<tr v-if="listing.directory">
										<td>{{ t('opencatalogi', 'Directory URL') }}</td>
										<td class="urlCell">
											<a :href="listing.directory" target="_blank" class="urlLink">
												{{ listing.directory }}
											</a>
										</td>
									</tr>
									<tr v-if="listing.publications">
										<td>{{ t('opencatalogi', 'Publications URL') }}</td>
										<td class="urlCell">
											<a :href="listing.publications" target="_blank" class="urlLink">
												{{ listing.publications }}
											</a>
										</td>
									</tr>
									<tr v-if="listing.search">
										<td>{{ t('opencatalogi', 'Search URL') }}</td>
										<td class="urlCell">
											<a :href="listing.search" target="_blank" class="urlLink">
												{{ listing.search }}
											</a>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>{{ t('opencatalogi', 'Name') }}</th>
									<th>{{ t('opencatalogi', 'Organization') }}</th>
									<th>{{ t('opencatalogi', 'Schemas') }}</th>

									<th>{{ t('opencatalogi', 'Publications URL') }}</th>
									<th>{{ t('opencatalogi', 'Search URL') }}</th>
									<th>{{ t('opencatalogi', 'Directory URL') }}</th>
									<th>{{ t('opencatalogi', 'Last Sync') }}</th>
									<th>{{ t('opencatalogi', 'Status') }}</th>
									<th class="tableColumnActions">
										{{ t('opencatalogi', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="listing in paginatedListings"
									:key="listing.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedListings.includes(listing.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedListings.includes(listing.id)"
											@update:checked="(checked) => toggleListingSelection(listing.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<component :is="getStatusIcon(listing)"
												:size="16"
												:class="getStatusClass(listing)"
												style="margin-right: 8px;" />
											<strong>{{ listing.name || listing.title }}</strong>
											<span v-if="listing.default" class="defaultBadge">
												<Star :size="12" />
												Default
											</span>
											<span v-if="!listing.available" class="errorBadge">
												<CloseCircle :size="12" />
												Disabled
											</span>
											<span v-if="listing.summary" class="textDescription textEllipsis">{{ listing.summary }}</span>
										</div>
									</td>
									<td class="tableColumnConstrained">
										<span v-if="listing.organization">{{ listing.organization.title || listing.organization }}</span>
										<span v-else>-</span>
									</td>
									<td>{{ listing.schemaCount || listing.schemas?.length || 0 }}</td>
									<td class="tableColumnUrl">
										<a v-if="listing.publications"
											:href="listing.publications"
											target="_blank"
											class="urlLink">
											{{ truncateUrl(listing.publications) }}
										</a>
										<span v-else>-</span>
									</td>
									<td class="tableColumnUrl">
										<a v-if="listing.search"
											:href="listing.search"
											target="_blank"
											class="urlLink">
											{{ truncateUrl(listing.search) }}
										</a>
										<span v-else>-</span>
									</td>
									<td class="tableColumnUrl">
										<a v-if="listing.directory"
											:href="listing.directory"
											target="_blank"
											class="urlLink">
											{{ truncateUrl(listing.directory) }}
										</a>
										<span v-else>-</span>
									</td>
									<td>{{ formatDate(listing.lastSync) }}</td>
									<td :class="getStatusClass(listing)">
										{{ getStatusLabel(listing) }}
									</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click @click="refreshDirectory(listing)">
												<template #icon>
													<Refresh :size="20" />
												</template>
												Sync Directory
											</NcActionButton>
											<NcActionButton close-after-click @click="toggleIntegrationLevel(listing)">
												<template #icon>
													<component :is="listing.integrationLevel === 'search' ? 'CloseCircle' : 'CheckCircle'" :size="20" />
												</template>
												{{ listing.integrationLevel === 'search' ? 'Disable' : 'Enable' }}
											</NcActionButton>
											<NcActionButton close-after-click @click="toggleDefault(listing)">
												<template #icon>
													<component :is="listing.default ? 'Star' : 'StarOutline'" :size="20" />
												</template>
												{{ listing.default ? 'Remove as Default' : 'Set as Default' }}
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				:current-page="currentPagination.page || 1"
				:total-pages="currentPagination.pages || Math.ceil(filteredListings.length / (currentPagination.limit || 20))"
				:total-items="currentPagination.total || filteredListings.length"
				:current-page-size="currentPagination.limit || 20"
				:min-items-to-show="0"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import _ from 'lodash'
import LayersOutline from 'vue-material-design-icons/LayersOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'DirectoryIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		NcButton,
		LayersOutline,
		DotsHorizontal,
		Refresh,
		Plus,
		Eye,
		HelpCircleOutline,
		TrashCanOutline,
		CheckCircle,
		AlertCircle,
		CloseCircle,
		Star,
		StarOutline,
		PaginationComponent,
	},
	data() {
		return {
			selectedListings: [],
			viewMode: 'cards',
		}
	},
	computed: {
		filteredListings() {
			return objectStore.getCollection('listing')?.results || []
		},
		currentPagination() {
			const pagination = objectStore.getPagination('listing')
			console.info('Current pagination data:', pagination)
			return pagination
		},
		paginatedListings() {
			return this.filteredListings
		},
		allSelected() {
			return this.filteredListings.length > 0 && this.filteredListings.every(listing => this.selectedListings.includes(listing.id))
		},
		someSelected() {
			return this.selectedListings.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (objectStore.isLoading('listing')) {
				return t('opencatalogi', 'Loading directory listings...')
			} else if (!objectStore.getCollection('listing')?.results?.length) {
				return t('opencatalogi', 'No directory listings found')
			}
			return ''
		},
		emptyContentDescription() {
			if (objectStore.isLoading('listing')) {
				return t('opencatalogi', 'Please wait while we fetch directory listings.')
			} else if (!objectStore.getCollection('listing')?.results?.length) {
				return t('opencatalogi', 'No directory listings are available.')
			}
			return ''
		},
	},
	mounted() {
		console.info('DirectoryIndex mounted, fetching listings...')
		objectStore.fetchCollection('listing')
	},
	methods: {
		setViewMode(mode) {
			console.info('Setting view mode to:', mode)
			this.viewMode = mode
		},
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedListings = this.filteredListings.map(listing => listing.id)
			} else {
				this.selectedListings = []
			}
		},
		toggleListingSelection(listingId, checked) {
			if (checked) {
				this.selectedListings.push(listingId)
			} else {
				this.selectedListings = this.selectedListings.filter(id => id !== listingId)
			}
		},
		onPageChanged(page) {
			console.info('Page changed to:', page)
			objectStore.fetchCollection('listing', { _page: page, _limit: this.currentPagination.limit || 20 })
		},
		onPageSizeChanged(pageSize) {
			console.info('Page size changed to:', pageSize)
			objectStore.fetchCollection('listing', { _page: 1, _limit: pageSize })
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
		getStatusIcon(listing) {
			const statusCode = listing.statusCode || listing.status
			if (statusCode >= 200 && statusCode < 300) {
				return CheckCircle
			} else if (!statusCode || statusCode === 0) {
				return AlertCircle
			} else {
				return CloseCircle
			}
		},
		getStatusClass(listing) {
			const statusCode = listing.statusCode || listing.status
			if (statusCode >= 200 && statusCode < 300) {
				return 'status-success'
			} else if (!statusCode || statusCode === 0) {
				return 'status-warning'
			} else {
				return 'status-error'
			}
		},
		getStatusText(listing) {
			const statusCode = listing.statusCode || listing.status
			if (statusCode) {
				return `HTTP ${statusCode}`
			}
			return 'Unknown'
		},
		getStatusLabel(listing) {
			const statusCode = listing.statusCode || listing.status
			if (statusCode >= 200 && statusCode < 300) {
				return 'Online'
			} else if (!statusCode || statusCode === 0) {
				return 'Unknown'
			} else {
				return 'Error'
			}
		},
		formatDate(dateString) {
			if (!dateString) return 'Never'

			try {
				const date = new Date(dateString)
				if (isNaN(date.getTime())) return 'Invalid'

				const now = new Date()
				const diffMs = now - date
				const diffHours = Math.floor(diffMs / (1000 * 60 * 60))
				const diffDays = Math.floor(diffHours / 24)

				if (diffHours < 1) {
					return 'Just now'
				} else if (diffHours < 24) {
					return `${diffHours} hours ago`
				} else if (diffDays < 7) {
					return `${diffDays} days ago`
				} else {
					return date.toLocaleDateString('nl-NL', {
						year: 'numeric',
						month: 'short',
						day: 'numeric',
					})
				}
			} catch (e) {
				return 'Invalid'
			}
		},
		async refreshDirectory(listing) {
			try {
				// Show loading state
				this.$set(listing, 'syncing', true)

				// Call the directory sync endpoint
				const response = await fetch(generateUrl('/apps/opencatalogi/api/directory'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						directory: listing.directory,
					}),
				})

				const result = await response.json()

				if (response.ok) {
					// Refresh the listings collection to show updated data
					await objectStore.fetchCollection('listing')

					// Show success notification
					OC.Notification.showMessage(
						`Directory "${listing.title || listing.name}" synced successfully`,
						{ type: 'success' },
					)
				} else {
					throw new Error(result.message || 'Sync failed')
				}
			} catch (error) {
				console.error('Failed to sync directory:', error)
				OC.Notification.showMessage(
					`Failed to sync directory: ${error.message}`,
					{ type: 'error' },
				)
			} finally {
				// Remove loading state
				this.$delete(listing, 'syncing')
			}
		},
		async toggleIntegrationLevel(listing) {
			try {
				// Update the listing locally first for immediate UI feedback
				const newIntegrationLevel = (!listing.integrationLevel || listing.integrationLevel === 'none' || listing.integrationLevel === 'connection') ? 'search' : 'connection'
				this.$set(listing, 'integrationLevel', newIntegrationLevel)

				// Update the listing on the server
				const updatedData = {
					...listing,
					integrationLevel: newIntegrationLevel,
				}

				await objectStore.updateObject('listing', listing.id, updatedData)

				// Show success notification
				OC.Notification.showMessage(
					`Directory "${listing.title || listing.name}" ${newIntegrationLevel ? 'enabled' : 'disabled'}`,
					{ type: 'success' },
				)
			} catch (error) {
				// Revert the local change on error
				this.$set(listing, 'integrationLevel', (!listing.integrationLevel || listing.integrationLevel === 'none' || listing.integrationLevel === 'connection') ? 'search' : 'connection')

				console.error('Failed to toggle integration level:', error)
				OC.Notification.showMessage(
					`Failed to update directory: ${error.message}`,
					{ type: 'error' },
				)
			}
		},
		async toggleDefault(listing) {
			try {
				const newDefaultState = !listing.default

				// If setting as default, first remove default from all other listings
				if (newDefaultState) {
					const allListings = this.filteredListings
					for (const otherListing of allListings) {
						if (otherListing.id !== listing.id && otherListing.default) {
							this.$set(otherListing, 'default', false)
							try {
								await objectStore.updateObject('listing', otherListing.id, {
									...otherListing,
									default: false,
								})
							} catch (error) {
								console.warn('Failed to remove default from other listing:', error)
							}
						}
					}
				}

				// Update the current listing
				this.$set(listing, 'default', newDefaultState)

				const updatedData = {
					...listing,
					default: newDefaultState,
				}

				await objectStore.updateObject('listing', listing.id, updatedData)

				// Show success notification
				OC.Notification.showMessage(
					`Directory "${listing.title || listing.name}" ${newDefaultState ? 'set as default' : 'removed as default'}`,
					{ type: 'success' },
				)
			} catch (error) {
				// Revert the local change on error
				this.$set(listing, 'default', !listing.default)

				console.error('Failed to toggle default state:', error)
				OC.Notification.showMessage(
					`Failed to update directory: ${error.message}`,
					{ type: 'error' },
				)
			}
		},
		truncateUrl(url) {
			if (!url) return ''
			// Remove protocol and www
			const cleanUrl = url.replace(/^https?:\/\/(www\.)?/, '')
			// If still too long, truncate and add ellipsis
			if (cleanUrl.length > 35) {
				return cleanUrl.substring(0, 32) + '...'
			}
			return cleanUrl
		},
	},
}
</script>

<style scoped>
.truncatedText {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}

/* Status styling */
.status-success {
	color: var(--color-success);
}

.status-warning {
	color: var(--color-warning);
}

.status-error {
	color: var(--color-error);
}

/* Default badge styling */
.defaultBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--color-primary);
	color: white;
	font-size: 0.7em;
	font-weight: bold;
	padding: 2px 6px;
	border-radius: 12px;
	margin-left: 8px;
	vertical-align: middle;
}

/* Error badge styling */
.errorBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--color-error);
	color: white;
	font-size: 0.7em;
	font-weight: bold;
	padding: 2px 6px;
	border-radius: 12px;
	margin-left: 8px;
	vertical-align: middle;
}

/* Card header adjustments */
.cardHeader h2 {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Table title content adjustments */
.titleContent {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 4px;
}

.titleContent .defaultBadge,
.titleContent .errorBadge {
	margin-left: 4px;
	margin-right: 0;
}

/* URL column styling */
.tableColumnUrl {
	max-width: 200px;
}

.urlCell {
	max-width: 300px;
	word-break: break-all;
}

.urlLink {
	color: var(--color-primary);
	text-decoration: none;
	font-size: 0.9em;
}

.urlLink:hover {
	text-decoration: underline;
	color: var(--color-primary-hover, var(--color-primary));
}
</style>
