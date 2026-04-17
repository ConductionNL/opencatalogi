<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnDetailPage
		:title="publication?.title || t('opencatalogi', 'Publication')"
		:description="publication?.summary || ''"
		icon="FileDocumentOutline"
		:loading="loading"
		:loading-label="t('opencatalogi', 'Loading publication...')"
		:empty="!publication && !loading"
		:empty-label="t('opencatalogi', 'Publication not found')"
		:error="!!error"
		:error-message="error"
		:on-retry="loadPublication"
		:layout="detailLayout"
		:widgets="widgetDefs"
		:sidebar="!!publication"
		:sidebar-open="sidebarOpen"
		object-type="publication"
		:object-id="publicationId"
		:sidebar-props="{ register: String(publication?.['@self']?.register || ''), schema: String(publication?.['@self']?.schema || '') }">
		<!-- Header actions -->
		<template #actions>
			<NcButton @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('opencatalogi', 'Back') }}
			</NcButton>
			<NcButton type="primary" @click="editPublication">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t('opencatalogi', 'Edit') }}
			</NcButton>
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton
					v-if="!isPublished"
					close-after-click
					@click="publishPublication">
					<template #icon>
						<Publish :size="20" />
					</template>
					{{ t('opencatalogi', 'Publish') }}
				</NcActionButton>
				<NcActionButton
					v-if="isPublished"
					close-after-click
					@click="depublishPublication">
					<template #icon>
						<PublishOff :size="20" />
					</template>
					{{ t('opencatalogi', 'Depublish') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="deletePublication">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					{{ t('opencatalogi', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</template>

		<!-- Object data widget (editable) -->
		<template #widget-object-data>
			<CnObjectDataWidget
				v-if="publication && schema"
				:title="t('opencatalogi', 'Publication Data')"
				:schema="schema"
				:object-data="publication"
				object-type="publication"
				:store="objectStoreInstance"
				:columns="2"
				:overrides="dataWidgetOverrides"
				:save-label="t('opencatalogi', 'Save')"
				:discard-label="t('opencatalogi', 'Discard')"
				@saved="onSaved" />
			<div v-else-if="!schema && !loading" class="empty-text">
				{{ t('opencatalogi', 'Schema not available') }}
			</div>
		</template>

		<!-- Object metadata widget (read-only) -->
		<template #widget-metadata>
			<CnObjectMetadataWidget
				v-if="publication"
				:title="t('opencatalogi', 'System Metadata')"
				:object-data="publication"
				layout="horizontal"
				:label-width="80"
				:include="['id', 'uri', 'register', 'schema', 'owner', 'organisation', 'created', 'updated', 'folder', 'locked', 'version']"
				:extra-items="[
					{ label: t('opencatalogi', 'Status'), value: isPublished ? t('opencatalogi', 'Published') : t('opencatalogi', 'Concept') },
				]" />
		</template>

	</CnDetailPage>
</template>

<script>
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnDetailPage, CnObjectDataWidget, CnObjectMetadataWidget, buildHeaders } from '@conduction/nextcloud-vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

const DETAIL_LAYOUT = [
	{ id: 1, widgetId: 'object-data', gridX: 0, gridY: 0, gridWidth: 8, gridHeight: 5, showTitle: false },
	{ id: 2, widgetId: 'metadata', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 5, showTitle: false },
]

export default {
	name: 'PublicationDetailPage',
	components: {
		CnDetailPage,
		CnObjectDataWidget,
		CnObjectMetadataWidget,
		NcButton,
		NcActions,
		NcActionButton,
		ArrowLeft,
		Pencil,
		DotsHorizontal,
		Publish,
		PublishOff,
		TrashCanOutline,
	},
	data() {
		return {
			publication: null,
			schema: null,
			loading: false,
			error: null,
			sidebarOpen: true,
			detailLayout: [...DETAIL_LAYOUT],
		}
	},
	computed: {
		objectStoreInstance() {
			return objectStore
		},
		publicationId() {
			return this.$route.params.id
		},
		catalogSlug() {
			return this.$route.params.catalogSlug
		},
		isPublished() {
			return !!this.publication?.['@self']?.published && !this.publication?.['@self']?.depublished
		},
		themeOptions() {
			const themes = objectStore.getCollection('theme').results || []
			return themes.map(theme => ({
				id: theme.id,
				label: theme.title || `#${theme.id}`,
			}))
		},
		dataWidgetOverrides() {
			return {
				title: { order: 1, gridColumn: 2 },
				summary: { order: 2, gridColumn: 2 },
				description: { order: 3, gridColumn: 2, gridRow: 2 },
				organization: { order: 4 },
				themes: { order: 5, widget: 'multiselect', enum: this.themeOptions },
			}
		},
		widgetDefs() {
			return [
				{ id: 'object-data', title: t('opencatalogi', 'Publication Data'), type: 'custom' },
				{ id: 'metadata', title: t('opencatalogi', 'System Metadata'), type: 'custom' },
			]
		},
	},
	watch: {
		publicationId: {
			immediate: true,
			handler() {
				if (this.publicationId) {
					this.loadPublication()
				}
			},
		},
	},
	mounted() {
		objectStore.fetchCollection('theme')
	},
	methods: {
		async loadPublication() {
			this.loading = true
			this.error = null
			try {
				const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
				const response = await fetch(
					`${prefix}/apps/opencatalogi/api/${this.catalogSlug}/${this.publicationId}`,
					{ method: 'GET', headers: buildHeaders() },
				)
				if (!response.ok) {
					throw new Error(`Failed to load publication (${response.status})`)
				}
				this.publication = await response.json()
				objectStore.setActiveObject('publication', this.publication)

				// Fetch the schema for the data widget
				const schemaId = this.publication['@self']?.schema
				if (schemaId) {
					await this.loadSchema(schemaId)
				}
			} catch (err) {
				this.error = err.message
				console.error('Failed to load publication:', err)
			} finally {
				this.loading = false
			}
		},
		async loadSchema(schemaId) {
			try {
				const prefix = window.location.pathname.includes('/index.php') ? '/index.php' : ''
				const response = await fetch(
					`${prefix}/apps/openregister/api/schemas/${schemaId}`,
					{ method: 'GET', headers: buildHeaders() },
				)
				if (!response.ok) {
					console.error(`Failed to load schema (${response.status})`)
					return
				}
				this.schema = await response.json()
			} catch (err) {
				console.error('Failed to load schema:', err)
			}
		},
		onSaved(result) {
			this.publication = result
			objectStore.setActiveObject('publication', result)
		},
		goBack() {
			this.$router.push({ name: 'Publications', params: { catalogSlug: this.catalogSlug } })
		},
		editPublication() {
			objectStore.setActiveObject('publication', this.publication)
			navigationStore.setModal('objectModal')
		},
		publishPublication() {
			objectStore.setActiveObject('publication', this.publication)
			navigationStore.setDialog('massPublishObjects')
		},
		depublishPublication() {
			objectStore.setActiveObject('publication', this.publication)
			navigationStore.setDialog('massDepublishObjects')
		},
		deletePublication() {
			objectStore.setActiveObject('publication', this.publication)
			navigationStore.setDialog('deleteObject', { objectType: 'publication', dialogTitle: 'Publicatie' })
		},
	},
}
</script>

<style scoped>
.empty-text {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 12px 16px;
}
</style>
