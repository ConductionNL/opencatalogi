<template>
	<NcContent app-name="opencatalogi">
		<MainMenu />
		<NcAppContent>
			<template #default>
				<router-view />
			</template>
		</NcAppContent>
		<router-view name="sidebar" />
		<Modals />
		<Dialogs />
	</NcContent>
</template>

<script>

import { NcContent, NcAppContent } from '@nextcloud/vue'
import MainMenu from './navigation/MainMenu.vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import { objectStore } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		MainMenu,
		Modals,
		Dialogs,
	},
	async mounted() {
		// Preload all collections when the app starts
		await objectStore.preloadCollections()
	},
}
</script>
