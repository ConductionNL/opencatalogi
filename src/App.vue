<template>
	<NcContent app-name="opencatalogi">
		<MainMenu @open-settings="settingsOpen = true" />
		<NcAppContent>
			<template #default>
				<router-view />
			</template>
		</NcAppContent>
		<router-view name="sidebar" />
		<Modals />
		<Dialogs />
		<UserSettings :open="settingsOpen" @update:open="settingsOpen = $event" />
	</NcContent>
</template>

<script>

import { NcContent, NcAppContent } from '@nextcloud/vue'
import MainMenu from './navigation/MainMenu.vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import UserSettings from './views/settings/UserSettings.vue'
import { objectStore } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		MainMenu,
		Modals,
		Dialogs,
		UserSettings,
	},
	data() {
		return {
			settingsOpen: false,
		}
	},
	async mounted() {
		// Preload all collections when the app starts
		await objectStore.preloadCollections()
	},
}
</script>
