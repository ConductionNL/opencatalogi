<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnDetailPage
		:title="entity?.title || entity?.name || entityLabel"
		:description="entity?.summary || ''"
		:icon="icon"
		:loading="loading"
		:loading-label="t('opencatalogi', 'Loading...')"
		:empty="!entity && !loading"
		:empty-label="t('opencatalogi', '{type} not found', { type: entityLabel })"
		:error="!!error"
		:error-message="error"
		:on-retry="loadEntity"
		:layout="detailLayout"
		:widgets="widgetDefs"
		:sidebar="!!entity"
		:sidebar-open="sidebarOpen"
		:object-type="entityType"
		:object-id="entityId"
		:sidebar-props="{ register: String(entity?.['@self']?.register || ''), schema: String(entity?.['@self']?.schema || '') }">
		<!-- Header actions -->
		<template #actions>
			<NcButton @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('opencatalogi', 'Back') }}
			</NcButton>
			<NcButton type="primary" @click="editEntity">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t('opencatalogi', 'Edit') }}
			</NcButton>
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="deleteEntity">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					{{ t('opencatalogi', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</template>

		<!-- Metadata widget -->
		<template #widget-metadata>
			<CnDetailGrid
				:items="metadataItems"
				layout="horizontal" />
		</template>

		<!-- Description widget -->
		<template #widget-description>
			<div class="description-content">
				<p v-if="entity?.description">
					{{ entity.description }}
				</p>
				<p v-else class="empty-text">
					{{ t('opencatalogi', 'No description provided') }}
				</p>
			</div>
		</template>

		<!-- Raw data widget -->
		<template #widget-raw-data>
			<CnJsonViewer
				:value="JSON.stringify(entity, null, 2)"
				language="json"
				:read-only="true"
				:height="300" />
		</template>
	</CnDetailPage>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailGrid, CnJsonViewer, buildHeaders } from '@conduction/nextcloud-vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

const DETAIL_LAYOUT = [
	{ id: 1, widgetId: 'metadata', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 4 },
	{ id: 2, widgetId: 'description', gridX: 0, gridY: 4, gridWidth: 12, gridHeight: 2 },
	{ id: 3, widgetId: 'raw-data', gridX: 0, gridY: 6, gridWidth: 12, gridHeight: 4 },
]

export default {
	name: 'EntityDetailPage',
	components: {
		CnDetailPage,
		CnDetailGrid,
		CnJsonViewer,
		NcButton,
		NcActions,
		NcActionButton,
		ArrowLeft,
		Pencil,
		DotsHorizontal,
		TrashCanOutline,
	},
	props: {
		/** Entity type slug (e.g., 'glossary', 'theme', 'page', 'menu') */
		entityType: {
			type: String,
			required: true,
		},
		/** Human-readable label for the entity type */
		entityLabel: {
			type: String,
			required: true,
		},
		/** MDI icon name */
		icon: {
			type: String,
			default: 'InformationOutline',
		},
		/** API path segment (e.g., 'glossary', 'themes', 'pages', 'menus') */
		apiPath: {
			type: String,
			required: true,
		},
		/** Route name to go back to */
		backRoute: {
			type: String,
			required: true,
		},
		/** Modal name to open for editing */
		editModal: {
			type: String,
			required: true,
		},
		/** Extra metadata items to display (beyond title/summary/dates).
		 *  Either a function `(entity) => [{label,value},...]` (legacy)
		 *  or null (use declarative `extraMetadataFields`). */
		extraMetadata: {
			type: Function,
			default: null,
		},
		/**
		 * Declarative metadata-field descriptors used when no
		 * `extraMetadata` function is provided. Each entry resolves a
		 * value from the entity object and produces a {label,value}
		 * row. Used by manifest-driven detail pages (config-only, no
		 * per-entity Vue wrapper file).
		 *
		 *   field   - dot-path on the entity (e.g. 'status', 'keywords')
		 *   label   - i18n key (resolved via t('opencatalogi', label))
		 *   format  - 'join' (array → comma list) | 'length' (.length) | 'string' (default)
		 *
		 * @type {Array<{field:string,label:string,format?:string}>}
		 */
		extraMetadataFields: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			entity: null,
			loading: false,
			error: null,
			sidebarOpen: true,
			detailLayout: [...DETAIL_LAYOUT],
		}
	},
	computed: {
		entityId() {
			return this.$route.params.id
		},
		metadataItems() {
			if (!this.entity) return []
			const self = this.entity['@self'] || {}
			// Resolve extra-metadata rows: prefer function-form (legacy
			// per-entity wrappers) over declarative form (manifest config).
			let extra = []
			if (typeof this.extraMetadata === 'function') {
				extra = this.extraMetadata(this.entity) || []
			} else if (this.extraMetadataFields.length) {
				extra = this.extraMetadataFields
					.map((descriptor) => {
						const raw = this.entity[descriptor.field]
						if (raw === undefined || raw === null || raw === '') return null
						let value = raw
						if (descriptor.format === 'join' && Array.isArray(raw)) {
							value = raw.length ? raw.join(', ') : null
						} else if (descriptor.format === 'length' && Array.isArray(raw)) {
							value = raw.length ? String(raw.length) : null
						}
						if (value === null) return null
						return { label: t('opencatalogi', descriptor.label), value }
					})
					.filter(Boolean)
			}
			const base = [
				{ label: t('opencatalogi', 'Title'), value: this.entity.title || this.entity.name || '-' },
				{ label: t('opencatalogi', 'Summary'), value: this.entity.summary || '-' },
				...extra,
				{ label: t('opencatalogi', 'Created'), value: self.created ? new Date(self.created).toLocaleString() : '-' },
				{ label: t('opencatalogi', 'Updated'), value: self.updated ? new Date(self.updated).toLocaleString() : '-' },
				{ label: t('opencatalogi', 'Owner'), value: self.owner || '-' },
				{ label: t('opencatalogi', 'ID'), value: self.id || this.entity.id || '-' },
			]
			return base
		},
		widgetDefs() {
			return [
				{ id: 'metadata', title: t('opencatalogi', 'Metadata'), type: 'custom' },
				{ id: 'description', title: t('opencatalogi', 'Description'), type: 'custom' },
				{ id: 'raw-data', title: t('opencatalogi', 'Data'), type: 'custom' },
			]
		},
	},
	watch: {
		entityId: {
			immediate: true,
			handler() {
				if (this.entityId) {
					this.loadEntity()
				}
			},
		},
	},
	methods: {
		async loadEntity() {
			this.loading = true
			this.error = null
			try {
				const response = await fetch(
					generateUrl(`/apps/opencatalogi/api/${this.apiPath}/${this.entityId}`),
					{ method: 'GET', headers: buildHeaders() },
				)
				if (!response.ok) throw new Error(`Failed to load ${this.entityLabel} (${response.status})`)
				this.entity = await response.json()
				objectStore.setActiveObject(this.entityType, this.entity)
			} catch (err) {
				this.error = err.message
			} finally {
				this.loading = false
			}
		},
		goBack() {
			this.$router.push({ name: this.backRoute })
		},
		editEntity() {
			objectStore.setActiveObject(this.entityType, this.entity)
			navigationStore.setModal(this.editModal)
		},
		deleteEntity() {
			objectStore.setActiveObject(this.entityType, this.entity)
			navigationStore.setDialog('deleteObject', { objectType: this.entityType, dialogTitle: this.entityLabel })
		},
	},
}
</script>

<style scoped>
.description-content {
	padding: 12px 16px;
	line-height: 1.6;
}

.empty-text {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 12px 16px;
}
</style>
