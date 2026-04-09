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
		<template #column-name="{ row }">
			<div class="titleContent">
				<component :is="getStatusIcon(row)" :size="16" :class="getStatusClass(row)" />
				<strong>{{ row.name || row.title }}</strong>
				<CnStatusBadge v-if="row.default" :label="t('opencatalogi', 'Default')" variant="primary" size="small" />
				<CnStatusBadge v-if="!row.available" :label="t('opencatalogi', 'Disabled')" variant="error" size="small" />
			</div>
		</template>
		<template #column-organization="{ row }">{{ row.organization?.title || row.organization || '-' }}</template>
		<template #column-schemas="{ row }">{{ row.schemaCount || row.schemas?.length || 0 }}</template>
		<template #column-publications="{ row }"><a v-if="row.publications" :href="row.publications" target="_blank" class="urlLink">{{ truncateUrl(row.publications) }}</a><span v-else>-</span></template>
		<template #column-search="{ row }"><a v-if="row.search" :href="row.search" target="_blank" class="urlLink">{{ truncateUrl(row.search) }}</a><span v-else>-</span></template>
		<template #column-directory="{ row }"><a v-if="row.directory" :href="row.directory" target="_blank" class="urlLink">{{ truncateUrl(row.directory) }}</a><span v-else>-</span></template>
		<template #column-lastSync="{ row }">{{ formatDate(row.lastSync) }}</template>
		<template #column-statusCode="{ row }"><CnStatusBadge :label="getStatusLabel(row)" :color-map="statusColorMap" /></template>
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon><DotsHorizontal :size="20" /></template>
				<NcActionButton close-after-click @click="refreshDirectory(row)"><template #icon><Refresh :size="20" /></template>{{ t('opencatalogi', 'Sync Directory') }}</NcActionButton>
				<NcActionButton close-after-click @click="toggleIntegrationLevel(row)"><template #icon><component :is="row.integrationLevel === 'search' ? CloseCircle : CheckCircle" :size="20" /></template>{{ row.integrationLevel === 'search' ? t('opencatalogi', 'Disable') : t('opencatalogi', 'Enable') }}</NcActionButton>
				<NcActionButton close-after-click @click="toggleDefault(row)"><template #icon><component :is="row.default ? Star : StarOutline" :size="20" /></template>{{ row.default ? t('opencatalogi', 'Remove as Default') : t('opencatalogi', 'Set as Default') }}</NcActionButton>
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
	components: { CnIndexPage, CnStatusBadge, NcActions, NcActionButton, DotsHorizontal, Refresh, CheckCircle, AlertCircle, CloseCircle, Star, StarOutline },
	data() { return { selectedIds: [], viewMode: 'cards', isRefreshing: false, statusColorMap: { [t('opencatalogi', 'Online')]: 'success', [t('opencatalogi', 'Unknown')]: 'warning', [t('opencatalogi', 'Error')]: 'error' } } },
	computed: {
		tableColumns() { return [{ key: 'name', label: t('opencatalogi', 'Name'), sortable: true }, { key: 'organization', label: t('opencatalogi', 'Organization') }, { key: 'schemas', label: t('opencatalogi', 'Schemas') }, { key: 'publications', label: t('opencatalogi', 'Publications URL') }, { key: 'search', label: t('opencatalogi', 'Search URL') }, { key: 'directory', label: t('opencatalogi', 'Directory URL') }, { key: 'lastSync', label: t('opencatalogi', 'Last Sync'), sortable: true }, { key: 'statusCode', label: t('opencatalogi', 'Status'), sortable: true }] },
		currentObjects() { const c = objectStore.getCollection('listing'); return Array.isArray(c) ? c : c?.results || [] },
		currentPagination() { return objectStore.getPagination('listing') || { total: 0, page: 1, pages: 1, limit: 20 } },
	},
	mounted() { objectStore.fetchCollection('listing') },
	methods: {
		onAdd() { navigationStore.setModal('addDirectory') },
		async handleRefresh() { this.isRefreshing = true; try { await objectStore.fetchCollection('listing') } finally { this.isRefreshing = false } },
		onPageChange(page) { objectStore.fetchCollection('listing', { _page: page }) },
		onPageSizeChange(size) { objectStore.fetchCollection('listing', { _page: 1, _limit: size }) },
		onSelect(ids) { this.selectedIds = ids },
		onRowClick(row) { objectStore.setActiveObject('listing', row); navigationStore.setModal('viewDirectory') },
		getStatusIcon(listing) { const s = listing.statusCode || listing.status; if (s >= 200 && s < 300) return CheckCircle; if (!s || s === 0) return AlertCircle; return CloseCircle },
		getStatusClass(listing) { const s = listing.statusCode || listing.status; if (s >= 200 && s < 300) return 'status-success'; if (!s || s === 0) return 'status-warning'; return 'status-error' },
		getStatusLabel(listing) { const s = listing.statusCode || listing.status; if (s >= 200 && s < 300) return t('opencatalogi', 'Online'); if (!s || s === 0) return t('opencatalogi', 'Unknown'); return t('opencatalogi', 'Error') },
		formatDate(dateString) { if (!dateString) return 'Never'; try { const d = new Date(dateString); if (isNaN(d.getTime())) return 'Invalid'; const h = Math.floor((new Date() - d) / 3600000); const days = Math.floor(h / 24); if (h < 1) return 'Just now'; if (h < 24) return `${h} hours ago`; if (days < 7) return `${days} days ago`; return d.toLocaleDateString('nl-NL', { year: 'numeric', month: 'short', day: 'numeric' }) } catch (e) { return 'Invalid' } },
		truncateUrl(url) { if (!url) return ''; const c = url.replace(/^https?:\/\/(www\.)?/, ''); return c.length > 35 ? c.substring(0, 32) + '...' : c },
		async refreshDirectory(listing) { try { const r = await fetch(generateUrl('/apps/opencatalogi/api/directory'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ directory: listing.directory }) }); const result = await r.json(); if (r.ok) { await objectStore.fetchCollection('listing'); OC.Notification.showMessage(`Directory "${listing.title || listing.name}" synced successfully`, { type: 'success' }) } else { throw new Error(result.message || 'Sync failed') } } catch (error) { console.error('Failed to sync directory:', error); OC.Notification.showMessage(`Failed to sync directory: ${error.message}`, { type: 'error' }) } },
		async toggleIntegrationLevel(listing) { try { const newLevel = (!listing.integrationLevel || listing.integrationLevel === 'none' || listing.integrationLevel === 'connection') ? 'search' : 'connection'; this.$set(listing, 'integrationLevel', newLevel); await objectStore.updateObject('listing', listing.id, { ...listing, integrationLevel: newLevel }); OC.Notification.showMessage(`Directory "${listing.title || listing.name}" ${newLevel === 'search' ? 'enabled' : 'disabled'}`, { type: 'success' }) } catch (error) { console.error('Failed to toggle integration level:', error); OC.Notification.showMessage(`Failed to update directory: ${error.message}`, { type: 'error' }) } },
		async toggleDefault(listing) { try { const nd = !listing.default; if (nd) { for (const o of this.currentObjects) { if (o.id !== listing.id && o.default) { this.$set(o, 'default', false); try { await objectStore.updateObject('listing', o.id, { ...o, default: false }) } catch (e) { console.warn('Failed to remove default:', e) } } } } this.$set(listing, 'default', nd); await objectStore.updateObject('listing', listing.id, { ...listing, default: nd }); OC.Notification.showMessage(`Directory "${listing.title || listing.name}" ${nd ? 'set as default' : 'removed as default'}`, { type: 'success' }) } catch (error) { this.$set(listing, 'default', !listing.default); console.error('Failed to toggle default:', error); OC.Notification.showMessage(`Failed to update directory: ${error.message}`, { type: 'error' }) } },
	},
}
</script>

<style scoped>
.titleContent { display: flex; align-items: center; gap: 8px; }
.status-success { color: var(--color-success); }
.status-warning { color: var(--color-warning); }
.status-error { color: var(--color-error); }
.urlLink { color: var(--color-primary); text-decoration: none; font-size: 0.9em; }
.urlLink:hover { text-decoration: underline; }
</style>
