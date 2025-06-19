<template>
	<NcDialog
		name="Thema's verwijderen"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>Thema's succesvol verwijderd</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>Er is iets fout gegaan bij het verwijderen van thema's</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>
		<p v-if="success === null">
			Weet je zeker dat je {{ `${themesToDelete.length === 1 ? '' : 'deze'} ${themesToDelete.length} ${themesToDelete.length === 1 ? 'thema' : 'thema\'s'}` }} definitief wilt verwijderen?
		</p>
		<ul>
			<li v-for="(theme, index) in themesToDelete" :key="index">
				<strong>{{ theme.title }}</strong>
			</li>
		</ul>
		<template #actions>
			<NcButton :disabled="loading" @click="cancel">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success !== null ? 'Sluiten' : 'Annuleer' }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				type="error"
				@click="deleteMultipleThemes">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-if="!loading" :size="20" />
				</template>
				Verwijderen
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { Publication } from '../../entities/index.js'
import { navigationStore, publicationStore, themeStore } from '../../store/store.js'

export default {
	name: 'DeleteMultipleThemesDialog',
	components: { NcDialog, NcButton, NcNoteCard, NcLoadingIcon, Cancel, Delete },
	props: {
		themesToDelete: { type: Array, required: true },
	},
	data() {
		return { loading: false, success: null, error: false }
	},
	methods: {
		deleteMultipleThemes() {
			this.loading = true
			
			// Update publication themes
			const publicationClone = JSON.parse(JSON.stringify(publicationStore.publicationItem))
			const themesClone = [...publicationClone.themes]
			this.themesToDelete.forEach(theme => {
				const index = themesClone.indexOf(theme.id)
				if (index !== -1) {
					themesClone.splice(index, 1)
				}
			})
			const updatedPublication = new Publication({
				...publicationStore.publicationItem,
				themes: themesClone,
				catalog: publicationStore.publicationItem.catalog.id ?? publicationStore.publicationItem.catalog,
				publicationType: publicationStore.publicationItem.publicationType,
			})
			publicationStore.editPublication(updatedPublication)
				.then(({ response }) => {
					this.loading = false
					this.success = response.ok
					// Wait for the user to read the feedback then close the model
					const self = this
					setTimeout(function() {
						self.success = null
						navigationStore.setDialog(false)
					}, 2000)
				})
				.catch((err) => {
					this.error = err
					this.loading = false
				})
		},
		cancel() {
			this.$emit('cancel')
			navigationStore.setDialog(false)
		},
	},
}
</script>

<style>
.modal__content {
	margin: var(--OC-margin-50);
	text-align: center;
}
.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}
.success {
	color: green;
}
</style> 