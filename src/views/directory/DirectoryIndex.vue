<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Directory')"
		:description="t('opencatalogi', 'Browse and manage directory listings from various catalogs')"
		:show-title="true"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('listing')"
		:selectable="true"
		:selected-ids="selectedIds"
		:show-view-toggle="true"
		:show-edit-action="false"
		:show-copy-action="false"
		:show-delete-action="false"
		:show-mass-import="false"
		:show-mass-export="false"
		:show-mass-copy="false"
		:show-mass-delete="false"
		:view-mode="viewMode"
		:add-label="t('opencatalogi', 'Add Directory')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No directory listings found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="handleRefresh"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<!-- Custom column: name with status icon and badges -->
		<template #column-name="{ row }">
			<div class="titleContent">
				<component :is="getStatusIcon(row)"
					:size="16"
					:class="getStatusClass(row)" />
				<strong>{{ row.name || row.title }}</strong>
				<CnStatusBadge v-if="row.default"
					:label="t('opencatalogi', 'Default')"
					variant="primary"
					size="small" />
				<CnStatusBadge v-if="!row.available"
					:label="t('opencatalogi', 'Disabled')"
					variant="error"
					size="small" />
			</div>
		</template>

		<!-- Custom column: organization -->
		<template #column-organization="{ row }">
			{{ row.organization?.title || row.organization || '-' }}
		</template>

		<!-- Custom column: schemas count -->
		<template #column-schemas="{ row }">
			{{ row.schemaCount || row.schemas?.length || 0 }}
		</template>

		<!-- Custom column: URLs -->
		<template #column-publications="{ row }">
			<a
				v-if="row.publications"
				:href="row.publications"
				target="_blank"
				class="urlLink">
				{{ truncateUrl(row.publications) }}
			</a>
			<span v-else>-</span>
		</template>

		<template #column-search="{ row }">
			<a
				v-if="row.search"
				:href="row.search"
				target="_blank"
				class="urlLink">
				{{ truncateUrl(row.search) }}
			</a>
			<span v-else>-</span>
		</template>

		<template #column-directory="{ row }">
			<a
				v-if="row.directory"
				:href="row.directory"
				target="_blank"
				class="urlLink">
				{{ truncateUrl(row.directory) }}
			</a>
			<span v-else>-</span>
		</template>

		<!-- Custom column: last sync -->
		<template #column-lastSync="{ row }">
			{{ formatDate(row.lastSync) }}
		</template>

		<!-- Custom column: status -->
		<template #column-statusCode="{ row }">
			<CnStatusBadge
				:label="getStatusLabel(row)"
				:color-map="statusColorMap" />
		</template>

		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="refreshDirectory(row)">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('opencatalogi', 'Sync Directory') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="toggleIntegrationLevel(row)">
					<template #icon>
						<component :is="row.integrationLevel === 'search' ? CloseCircle : CheckCircle" :size="20" />
					</template>
					{{ row.integrationLevel === 'search' ? t('opencatalogi', 'Disable') : t('opencatalogi', 'Enable') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="toggleDefault(row)">
					<template #icon>
						<component :is="row.default ? Star : StarOutline" :size="20" />
					</template>
					{{ row.default ? t('opencatalogi', 'Remove as Default') : t('opencatalogi', 'Set as Default') }}
				</NcActionButton>
			</NcActions>
		</template>
	</CnIndexPage>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'

export default {
	name: 'DirectoryIndex',
	components: {
		CnIndexPage,
		CnStatusBadge,
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Refresh,
		CheckCircle,
		AlertCircle,
		CloseCircle,
		Star,
		StarOutline,
	},
	data() {
		return {
			selectedIds: [],
			viewMode: 'cards',
			isRefreshing: false,
			statusColorMap: {
				[t('opencatalogi', 'Online')]: 'success',
				[t('opencatalogi', 'Unknown')]: 'warning',
				[t('opencatalogi', 'Error')]: 'error',
			},
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'name', label: t('opencatalogi', 'Name'), sortable: true },
				{ key: 'organization', label: t('opencatalogi', 'Organization') },
				{ key: 'schemas', label: t('opencatalogi', 'Schemas') },
				{ key: 'publications', label: t('opencatalogi', 'Publications URL') },
				{ key: 'search', label: t('opencatalogi', 'Search URL') },
				{ key: 'directory', label: t('opencatalogi', 'Directory URL') },
				{ key: 'lastSync', label: t('opencatalogi', 'Last Sync'), sortable: true },
				{ key: 'statusCode', label: t('opencatalogi', 'Status'), sortable: true },
			]
		},
		currentObjects() {
			const collection = objectStore.getCollection('listing')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('listing')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	mounted() {
		objectStore.fetchCollection('listing')
	},
	methods: {
		onAdd() {
			navigationStore.setModal('addDirectory')
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await objectStore.fetchCollection('listing')
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChange(page) {
			objectStore.fetchCollection('listing', { _page: page, _limit: this.currentPagination.limit || 20 })
		},
		onPageSizeChange(size) {
			objectStore.fetchCollection('listing', { _page: 1, _limit: size })
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		onRowClick(row) {
			objectStore.setActiveObject('listing', row)
			navigationStore.setModal('viewDirectory')
		},
		getStatusIcon(listing) {
			const statusCode = listing.statusCode || listing.status
			if (statusCode >= 200 && statusCode < 300) return CheckCircle
			if (!statusCode || statusCode === 0) return AlertCircle
			return CloseCircle
		},
		getStatusClass(listing) {
			const statusCode = listing.statusCode || listing.status
			if (statusCode >= 200 && statusCode < 300) return 'status-success'
			if (!statusCode || statusCode === 0) return 'status-warning'
			return 'status-error'
		},
		getStatusLabel(listing) {
			const statusCode = listing.statusCode || listing.status
			if (statusCode >= 200 && statusCode < 300) return t('opencatalogi', 'Online')
			if (!statusCode || statusCode === 0) return t('opencatalogi', 'Unknown')
			return t('opencatalogi', 'Error')
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
				if (diffHours < 1) return 'Just now'
				if (diffHours < 24) return `${diffHours} hours ago`
				if (diffDays < 7) return `${diffDays} days ago`
				return date.toLocaleDateString('nl-NL', { year: 'numeric', month: 'short', day: 'numeric' })
			} catch (e) {
				return 'Invalid'
			}
		},
		truncateUrl(url) {
			if (!url) return ''
			const cleanUrl = url.replace(/^https?:\/\/(www\.)?/, '')
			if (cleanUrl.length > 35) return cleanUrl.substring(0, 32) + '...'
			return cleanUrl
		},
		async refreshDirectory(listing) {
			try {
				const response = await fetch(generateUrl('/apps/opencatalogi/api/directory'), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ directory: listing.directory }),
				})
				const result = await response.json()
				if (response.ok) {
					await objectStore.fetchCollection('listing')
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
			}
		},
		async toggleIntegrationLevel(listing) {
			try {
				const newLevel = (!listing.integrationLevel || listing.integrationLevel === 'none' || listing.integrationLevel === 'connection') ? 'search' : 'connection'
				this.$set(listing, 'integrationLevel', newLevel)
				await objectStore.updateObject('listing', listing.id, { ...listing, integrationLevel: newLevel })
				OC.Notification.showMessage(
					`Directory "${listing.title || listing.name}" ${newLevel === 'search' ? 'enabled' : 'disabled'}`,
					{ type: 'success' },
				)
			} catch (error) {
				console.error('Failed to toggle integration level:', error)
				OC.Notification.showMessage(`Failed to update directory: ${error.message}`, { type: 'error' })
			}
		},
		async toggleDefault(listing) {
			try {
				const newDefaultState = !listing.default
				if (newDefaultState) {
					for (const other of this.currentObjects) {
						if (other.id !== listing.id && other.default) {
							this.$set(other, 'default', false)
							try {
								await objectStore.updateObject('listing', other.id, { ...other, default: false })
							} catch (error) {
								console.warn('Failed to remove default from other listing:', error)
							}
						}
					}
				}
				this.$set(listing, 'default', newDefaultState)
				await objectStore.updateObject('listing', listing.id, { ...listing, default: newDefaultState })
				OC.Notification.showMessage(
					`Directory "${listing.title || listing.name}" ${newDefaultState ? 'set as default' : 'removed as default'}`,
					{ type: 'success' },
				)
			} catch (error) {
				this.$set(listing, 'default', !listing.default)
				console.error('Failed to toggle default state:', error)
				OC.Notification.showMessage(`Failed to update directory: ${error.message}`, { type: 'error' })
			}
		},
	},
}
</script>

<style scoped>
.titleContent {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-success { color: var(--color-success); }
.status-warning { color: var(--color-warning); }
.status-error { color: var(--color-error); }

.urlLink {
	color: var(--color-primary);
	text-decoration: none;
	font-size: 0.9em;
}

.urlLink:hover {
	text-decoration: underline;
}
</style>
