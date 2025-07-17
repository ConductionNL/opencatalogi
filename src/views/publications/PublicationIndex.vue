<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
import { ref, onMounted, computed } from 'vue'
</script>

<template>
	<div>
		<!-- Show loading state while fetching settings -->
		<NcLoadingIcon v-if="loadingSettings" :size="64" class="loadingIcon" appearance="dark" name="Loading settings..." />
		
		<!-- Show new table view if use_old_style_publishing_view is false (default) -->
		<PublicationTable v-else-if="!useOldStyleView" />
		
		<!-- Show old style view if use_old_style_publishing_view is true -->
		<NcAppContent v-else>
			<template #list>
				<PublicationList />
			</template>
			<template #default>
				<NcEmptyContent v-if="showEmptyContent"
					class="detailContainer"
					name="Geen publicatie"
					description="Nog geen publicatie geselecteerd">
					<template #icon>
						<ListBoxOutline />
					</template>
					<template #action>
						<NcButton type="primary" @click="objectStore.clearActiveObject('publication'); navigationStore.setModal('objectModal')">
							Publicatie toevoegen
						</NcButton>
					</template>
				</NcEmptyContent>
				<PublicationDetails v-if="!showEmptyContent" />
			</template>
		</NcAppContent>
	</div>
</template>

<script>
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { NcAppContent, NcEmptyContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import PublicationList from './PublicationList.vue'
import PublicationDetails from './PublicationDetail.vue'
import PublicationTable from './PublicationTable.vue'
import ListBoxOutline from 'vue-material-design-icons/ListBoxOutline.vue'

// Make the stores reactive
const { selected } = storeToRefs(navigationStore)
const activePublication = computed(() => objectStore.getActiveObject('publication'))

const showEmptyContent = computed(() => {
	const hasActivePublication = activePublication.value
	const isPublicationSelected = selected.value === 'publication'
	return !hasActivePublication || !isPublicationSelected
})

export default {
	name: 'PublicationIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		ListBoxOutline,
		PublicationList,
		PublicationDetails,
		PublicationTable,
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			useOldStyleView: false,
			loadingSettings: true,
		}
	},
	async mounted() {
		await this.loadPublishingSettings()
	},
	methods: {
		/**
		 * Load publishing settings from the API to determine which view to show
		 * 
		 * @return {Promise<void>} Promise that resolves when settings are loaded
		 */
		async loadPublishingSettings() {
			try {
				this.loadingSettings = true
				
				// Fetch publishing settings from the API
				const response = await fetch('/index.php/apps/opencatalogi/api/settings/publishing', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})
				
				if (!response.ok) {
					console.warn('Failed to load publishing settings, using default view')
					this.useOldStyleView = false
					return
				}
				
				const settings = await response.json()
				
				// Set the view mode based on the setting
				// Default to false (new table view) if setting is not present
				this.useOldStyleView = settings.use_old_style_publishing_view === true
				
				console.info('Publishing settings loaded:', settings)
				console.info('Using old style view:', this.useOldStyleView)
				
			} catch (error) {
				console.error('Error loading publishing settings:', error)
				// Default to new table view on error
				this.useOldStyleView = false
			} finally {
				this.loadingSettings = false
			}
		},
	},
}
</script>

<style scoped>
.loadingIcon {
	margin: 50px auto;
	display: block;
}
</style>
