<script setup>
import { catalogiStore, publicationTypeStore, navigationStore, publicationStore, themeStore, organizationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div class="head">
			<h1 class="h1">
				{{ publicationStore.publicationItem?.title }}
			</h1>

			<NcActions :disabled="loading"
				:primary="true"
				:menu-name="loading ? 'Laden...' : 'Acties'"
				:inline="1"
				title="Acties die je kan uitvoeren op deze publicatie">
				<template #icon>
					<span>
						<NcLoadingIcon v-if="loading" :size="20" appearance="dark" />
						<DotsHorizontal v-if="!loading" :size="20" />
					</span>
				</template>
				<NcActionButton title="Bekijk de documentatie over publicaties"
					@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/gebruikers/publicaties', '_blank')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					Help
				</NcActionButton>
				<NcActionButton @click="navigationStore.setModal('editPublication')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Bewerken
				</NcActionButton>
				<NcActionButton @click="navigationStore.setDialog('copyPublication')">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					Kopiëren
				</NcActionButton>
				<NcActionButton v-if="publicationStore.publicationItem?.status !== 'Published'"
					@click="publicationStore.setPublicationItem(publication); navigationStore.setDialog('publishPublication')">
					<template #icon>
						<Publish :size="20" />
					</template>
					Publiceren
				</NcActionButton>
				<NcActionButton v-if="publicationStore.publicationItem?.status === 'Published'"
					@click="publicationStore.setPublicationItem(publication); navigationStore.setDialog('depublishPublication')">
					<template #icon>
						<PublishOff :size="20" />
					</template>
					Depubliceren
				</NcActionButton>
				<NcActionButton @click="navigationStore.setDialog('archivePublication')">
					<template #icon>
						<ArchivePlusOutline :size="20" />
					</template>
					Archiveren
				</NcActionButton>
				<NcActionButton @click="navigationStore.setDialog('downloadPublication')">
					<template #icon>
						<Download :size="20" />
					</template>
					Downloaden
				</NcActionButton>
				<NcActionButton @click="navigationStore.setModal('addPublicationData')">
					<template #icon>
						<FileTreeOutline :size="20" />
					</template>
					Eigenschap toevoegen
				</NcActionButton>
				<NcActionButton @click="addAttachment">
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					Bijlage toevoegen
				</NcActionButton>
				<NcActionButton @click="navigationStore.setModal('addPublicationTheme')">
					<template #icon>
						<ShapeOutline :size="20" />
					</template>
					Thema toevoegen
				</NcActionButton>
				<NcActionButton @click="navigationStore.setDialog('deletePublication')">
					<template #icon>
						<Delete :size="20" />
					</template>
					Verwijderen
				</NcActionButton>
			</NcActions>
		</div>
		<div class="container">
			<div class="detailGrid">
				<div>
					<b>Referentie:</b>
					<span>{{ publicationStore.publicationItem?.reference }}</span>
				</div>
				<div>
					<b>Samenvatting:</b>
					<span>{{ publicationStore.publicationItem?.summary }}</span>
				</div>
				<div>
					<b>Beschrijving:</b>
					<span>{{ publicationStore.publicationItem?.description }}</span>
				</div>
				<div>
					<b>Categorie:</b>
					<span>{{ publicationStore.publicationItem?.category }}</span>
				</div>
				<div>
					<b>Portal:</b>
					<span><a target="_blank" :href="publicationStore.publicationItem?.portal">{{
						publicationStore.publicationItem?.portal }}</a></span>
				</div>
				<div>
					<b>Afbeelding:</b>
					<span>{{ publicationStore.publicationItem?.image }}</span>
				</div>
				<div>
					<b>Uitgelicht:</b>
					<span>{{ publicationStore.publicationItem?.featured ? "Ja" : "Nee" }}</span>
				</div>
				<div>
					<b>Licentie:</b>
					<span>{{ publicationStore.publicationItem?.license }}</span>
				</div>
				<div>
					<b>Status:</b>
					<span>{{ publicationStore.publicationItem?.status }}</span>
				</div>
				<div>
					<b>Gepubliceerd:</b>
					<span>{{ new Date(publicationStore.publicationItem?.published).toLocaleDateString() }}</span>
				</div>
				<div>
					<b>Gewijzigd:</b>
					<span>{{ publicationStore.publicationItem?.modified }}</span>
				</div>
				<div>
					<b>Bron:</b>
					<span>{{ publicationStore.publicationItem?.source }}</span>
				</div>
				<div>
					<b>Catalogi:</b>
					<span v-if="catalogiLoading">Loading...</span>
					<div v-if="!catalogiLoading" class="buttonLinkContainer">
						<span>{{ catalogi?.title }}</span>
						<NcActions>
							<NcActionLink :aria-label="`ga naar ${catalogi?.title}`"
								:name="catalogi?.title"
								@click="goToCatalogi()">
								<template #icon>
									<OpenInApp :size="20" />
								</template>
								{{ catalogi?.title }}
							</NcActionLink>
						</NcActions>
					</div>
				</div>
				<div>
					<b>Publicatietype:</b>
					<span v-if="publicationTypeLoading">Loading...</span>
					<div v-if="!publicationTypeLoading" class="buttonLinkContainer">
						<span>{{ publicationType?.title }}</span>
						<NcActions>
							<NcActionLink :aria-label="`ga naar ${publicationType?.title}`"
								:name="publicationType?.title"
								@click="goToPublicationType()">
								<template #icon>
									<OpenInApp :size="20" />
								</template>
								{{ publicationType?.title }}
							</NcActionLink>
						</NcActions>
					</div>
				</div>
				<div>
					<b>Organisatie:</b>
					<span v-if="organizationLoading">Loading...</span>
					<div v-if="!organizationLoading && organization?.title" class="buttonLinkContainer">
						<span>{{ organization?.title }}</span>
						<NcActions>
							<NcActionLink :aria-label="`ga naar ${organization?.title}`"
								:name="organization?.title"
								@click="goToOrganization()">
								<template #icon>
									<OpenInApp :size="20" />
								</template>
								{{ organization?.title }}
							</NcActionLink>
						</NcActions>
					</div>
					<div v-if="!organizationLoading && !organization?.title" class="buttonLinkContainer">
						<span>Geen organisatie gekoppeld</span>
					</div>
				</div>
			</div>
			<div class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<BTab title="Bijlagen" active>
						<div class="tabPanel">
							<div class="attachmantButtonsContainer">
								<NcButton type="primary"
									class="fullWidthButton"
									aria-label="Bijlage toevoegen"
									@click="addAttachment">
									<template #icon>
										<Plus :size="20" />
									</template>
									Bijlage toevoegen
								</NcButton>
								<NcButton type="secondary"
									aria-label="Open map"
									@click="openFolder(publicationStore.publicationItem?.['@self']?.folder)">
									<template #icon>
										<FolderOutline :size="20" />
									</template>
								</NcButton>
							</div>

							<div v-if="publicationStore.publicationAttachments?.length > 0">
								<NcListItem v-for="(attachment, i) in publicationStore.publicationAttachments"
									:key="`${attachment}${i}`"
									:name="attachment.name ?? attachment?.title"
									:bold="false"
									:active="publicationStore.attachmentItem?.id === attachment.id"
									:force-display-actions="true"
									@click="setActiveAttachment(attachment)">
									<template #icon>
										<ExclamationThick v-if="!attachment.accessUrl || !attachment.downloadUrl" class="warningIcon" :size="44" />
										<FileOutline v-else
											class="publishedIcon"
											disable-menu
											:size="44" />
									</template>

									<template #details>
										<span>{{ formatFileSize(attachment?.size) }}</span>
									</template>
									<template #indicator>
										<div class="fileLabelsContainer">
											<NcCounterBubble v-for="label of attachment.labels" :key="label">
												{{ label }}
											</NcCounterBubble>
										</div>
									</template>
									<template #subname>
										{{ attachment?.type || 'Geen type' }}
									</template>
									<template #actions>
										<NcActionButton @click="openFile(attachment)">
											<template #icon>
												<OpenInNew :size="20" />
											</template>
											Bekijk bestand
										</NcActionButton>
									</template>
								</NcListItem>
							</div>

							<div v-if="publicationStore.publicationAttachments?.length === 0">
								Nog geen bijlage toegevoegd
							</div>

							<div
								v-if="publicationStore.publicationAttachments?.length !== 0 && !publicationStore.publicationAttachments?.length > 0">
								<NcLoadingIcon :size="64"
									class="loadingIcon"
									appearance="dark"
									name="Bijlagen aan het laden" />
							</div>
						</div>
					</BTab>
					<BTab title="Eigenschappen">
						<div v-if="Object.keys(publicationStore.publicationItem?.data).length > 0">
							<NcListItem v-for="(value, key, i) in publicationStore.publicationItem?.data"
								:key="`${key}${i}`"
								:name="key"
								:bold="false"
								:force-display-actions="true"
								:active="publicationStore.publicationDataKey === key"
								@click="setActiveDataKey(key)">
								<template #icon>
									<CircleOutline
										:class="publicationStore.publicationDataKey === key && 'selectedZaakIcon'"
										disable-menu
										:size="44" />
								</template>
								<template #subname>
									{{ value }}
								</template>
								<template #actions>
									<NcActionButton @click="editPublicationDataItem(key)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Bewerken
									</NcActionButton>
									<NcActionButton @click="deletePublicationDataItem(key)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Verwijderen
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="Object.keys(publicationStore.publicationItem?.data).length === 0" class="tabPanel">
							Geen eigenschappen gevonden
						</div>
					</BTab>
					<BTab title="Thema's">
						<div v-if="filteredThemes?.length || missingThemes?.length">
							<NcListItem v-for="(value, key, i) in filteredThemes"
								:key="`${value.id}${i}`"
								:name="value.title"
								:bold="false"
								:force-display-actions="true"
								:active="themeStore.themeItem?.id === value.id">
								<template #icon>
									<ShapeOutline
										:class="themeStore.themeItem?.id === value.id && 'selectedZaakIcon'"
										disable-menu
										:size="44" />
								</template>
								<template #subname>
									{{ value.summary }}
								</template>
								<template #actions>
									<NcActionButton @click="themeStore.setThemeItem(value); navigationStore.setSelected('themes')">
										<template #icon>
											<OpenInApp :size="20" />
										</template>
										Bekijken
									</NcActionButton>
									<NcActionButton @click="themeStore.setThemeItem(value); navigationStore.setDialog('deletePublicationThemeDialog')">
										<template #icon>
											<Delete :size="20" />
										</template>
										Verwijderen
									</NcActionButton>
								</template>
							</NcListItem>
							<NcListItem v-for="(value, key, i) in missingThemes"
								:key="`${value}${i}`"
								:name="'Thema ' + value"
								:bold="false"
								:force-display-actions="true">
								<template #icon>
									<Alert disable-menu
										:size="44" />
								</template>
								<template #subname>
									Thema {{ value }} bestaat niet, het is aan te raden om het te verwijderen van deze publicatie.
								</template>
								<template #actions>
									<NcActionButton :disabled="deleteThemeLoading" @click="deleteMissingTheme(value)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Verwijderen
									</NcActionButton>
								</template>
							</NcListItem>
						</div>
						<div v-if="!filteredThemes?.length && !missingThemes?.length" class="tabPanel">
							Geen thema's gevonden
						</div>
					</BTab>
					<BTab title="Logging">
						<table width="100%">
							<tr>
								<th><b>Tijdstip</b></th>
								<th><b>Gebruiker</b></th>
								<th><b>Actie</b></th>
								<th><b>Details</b></th>
							</tr>
							<tr>
								<td>18-07-2024 11:55:21</td>
								<td>Ruben van der Linde</td>
								<td>Created</td>
								<td>
									<NcButton @click="navigationStore.setDialog('viewLog')">
										<template #icon>
											<TimelineQuestionOutline :size="20" />
										</template>
										Bekijk details
									</NcButton>
								</td>
							</tr>
						</table>
					</BTab>
					<BTab title="Rechten">
						<table width="100%">
							<tr>
								<td>Deze publicatie is <b v-if="prive">NIET</b> openbaar toegankelijk</td>
								<td>
									<NcButton @click="prive = !prive">
										<template #icon>
											<LockOpenVariantOutline v-if="!prive" :size="20" />
											<LockOutline v-if="prive" :size="20" />
										</template>
										<span v-if="!prive">Privé maken</span>
										<span v-if="prive">Openbaar maken</span>
									</NcButton>
								</td>
							</tr>
							<tr v-if="prive">
								<td>Gebruikersgroepen</td>
								<td>
									<NcSelectTags v-model="userGroups"
										input-label="gebruikers groepen"
										:multiple="true" />
								</td>
							</tr>
						</table>
					</BTab>
					<BTab title="Statistieken">
						<apexchart v-if="publication.status === 'Published'"
							width="100%"
							type="line"
							:options="chart.options"
							:series="chart.series" />
						<NcNoteCard type="info">
							<p>Er zijn nog geen statistieken over deze publicatie bekend</p>
						</NcNoteCard>
					</BTab>
				</BTabs>
			</div>
		</div>
	</div>
</template>

<script>
// Components
import { NcActionButton, NcActions, NcButton, NcListItem, NcLoadingIcon, NcNoteCard, NcSelectTags, NcActionLink, NcCounterBubble } from '@nextcloud/vue'
import { BTab, BTabs } from 'bootstrap-vue'
import VueApexCharts from 'vue-apexcharts'

// Icons
import ArchivePlusOutline from 'vue-material-design-icons/ArchivePlusOutline.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import LockOpenVariantOutline from 'vue-material-design-icons/LockOpenVariantOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import { Publication } from '../../entities/index.js'

export default {
	name: 'PublicationDetail',
	components: {
		// Components
		NcLoadingIcon,
		NcActionButton,
		NcActions,
		NcButton,
		NcListItem,
		NcSelectTags,
		NcNoteCard,
		NcActionLink,
		BTab,
		BTabs,
		apexchart: VueApexCharts,
	},
	props: {
		publicationItem: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			publication: [],
			catalogi: [],
			publicationType: [],
			organization: [],
			themes: [],
			prive: false,
			loading: false,
			catalogiLoading: false,
			publicationTypeLoading: false,
			organizationLoading: false,
			hasUpdated: false,
			userGroups: [
				{
					id: '1',
					label: 'Content Beheerders',
				},
			],
			chart: {
				options: {
					chart: {
						id: 'Aantal bekeken publicaties',
					},
					xaxis: {
						categories: ['7-11', '7-12', '7-13', '7-15', '7-16', '7-17', '7-18'],
					},
				},
				series: [{
					name: 'Weergaven',
					data: [0, 0, 0, 0, 0, 0, 15],
				}],
			},
			upToDate: false,
			deleteThemeLoading: false,
		}
	},
	computed: {
		filteredThemes() {
			return themeStore.themeList.filter((theme) => this.publication?.themes?.includes(theme.id))
		},
		missingThemes() { // themes (id's)- which are on the publication but do not exist on the themeList
			return this.publication?.themes?.filter((themeId) => !themeStore.themeList.map((theme) => theme.id).includes(themeId))
		},
	},
	watch: {
		publicationItem: {
			handler(newPublicationItem, oldPublicationItem) {

				if (!this.upToDate || JSON.stringify(newPublicationItem) !== JSON.stringify(oldPublicationItem)) {
					this.publication = publicationStore.publicationItem
					this.fetchCatalogi(publicationStore.publicationItem?.catalog?.id ?? publicationStore.publicationItem.catalogi)
					this.fetchPublicationType(publicationStore.publicationItem?.publicationType)
					this.fetchThemes()
					publicationStore.publicationItem?.id && this.fetchData(publicationStore.publicationItem.id)
				}
			},
			deep: true,
		},

	},
	mounted() {
		this.publication = publicationStore.publicationItem

		this.fetchCatalogi(this.publication.catalog?.id ?? this.publication.catalog)
		this.fetchPublicationType(publicationStore.publicationItem.publicationType)
		this.fetchThemes()
		publicationStore.publicationItem?.id && this.fetchData(publicationStore.publicationItem.id)

	},
	methods: {
		fetchData(id) {
			// this.loading = true

			publicationStore.getOnePublication(id, { doNotSetStore: true })
				.then(({ response, data }) => {
					this.publication = data
					// this.oldZaakId = id
					this.fetchCatalogi(data.catalog?.id ?? data.catalog)
					this.fetchPublicationType(data.publicationType)
					this.fetchThemes()
					publicationStore.getPublicationAttachments(id)
					data?.organization && this.fetchOrganization(data.organization, true)
					// this.loading = false
				})
				.catch((err) => {
					console.error(err)
					// this.oldZaakId = id
					// this.loading = false
				})
		},
		fetchCatalogi(catalogiId, loading) {
			if (loading) { this.catalogiLoading = true }

			catalogiStore.getOneCatalogi(catalogiId, { doNotSetStore: true })
				.then(({ response, data }) => {
					this.catalogi = data

					if (loading) { this.catalogiLoading = false }
				})
				.catch((err) => {
					console.error(err)
					if (loading) { this.catalogiLoading = false }
				})
		},
		fetchOrganization(organizationId, loading) {
			if (loading) { this.organizationLoading = true }

			organizationStore.getOneOrganization(organizationId, { doNotSetStore: true })
				.then(({ response, data }) => {
					this.organization = data

					if (loading) { this.organizationLoading = false }
				})
				.catch((err) => {
					console.error(err)
					if (loading) { this.organizationLoading = false }
				})
		},
		fetchPublicationType(publicationTypeUrl, loading) {
			if (loading) this.publicationTypeLoading = true

			const validUrl = this.validUrl(publicationTypeUrl)

			if (validUrl) {
				fetch(`/index.php/apps/opencatalogi/api/publication_types?source=${publicationTypeUrl}`, {
					method: 'GET',
				})
					.then((response) => {
						response.json().then((data) => {
							this.publicationType = data.results[0]
							publicationStore.setPublicationPublicationType(data.results[0])
						})
						if (loading) { this.publicationTypeLoading = false }
					})
					.catch((err) => {
						console.error(err)
						if (loading) { this.publicationTypeLoading = false }
					})
			} else {
				fetch(`/index.php/apps/opencatalogi/api/publication_types?id=${publicationTypeUrl}`, {
					method: 'GET',
				})
					.then((response) => {
						response.json().then((data) => {
							this.publicationType = data.results[0]
							publicationStore.setPublicationPublicationType(data.results[0])
						})
						if (loading) { this.publicationTypeLoading = false }
					})
					.catch((err) => {
						console.error(err)
						if (loading) { this.publicationTypeLoading = false }
					})
			}
		},
		fetchThemes(themes, loading) {
			if (loading) { this.themesLoading = true }

			themeStore.refreshThemeList()
				.then(({ response, data }) => {
					this.themes = data

					if (loading) { this.themesLoading = false }
				})
				.catch((err) => {
					console.error(err)
					if (loading) { this.themesLoading = false }
				})
		},
		validUrl(url) {
			try {
				const test = new URL(url)
				return test.href
			} catch (err) {
				return false
			}
		},
		getTime() {
			const timeNow = new Date().toISOString()
			return timeNow
		},
		addAttachment() {
			publicationStore.setAttachmentItem([])
			navigationStore.setModal('AddAttachment')
		},
		deletePublication() {
			publicationStore.setPublicationItem(this.publication)
			navigationStore.setModal('deletePublication')
		},
		editPublicationDataItem(key) {
			publicationStore.setPublicationDataKey(key)
			navigationStore.setModal('editPublicationData')
		},
		deletePublicationDataItem(key) {
			publicationStore.setPublicationDataKey(key)
			navigationStore.setDialog('deletePublicationDataDialog')
		},
		editPublicationAttachmentItem(key) {
			publicationStore.setPublicationDataKey(key)
			navigationStore.setModal('editPublicationDataModal')
		},
		goToPublicationType() {
			publicationTypeStore.setPublicationTypeItem(this.publicationType)
			navigationStore.setSelected('publicationType')
		},
		goToOrganization() {
			organizationStore.setOrganizationItem(this.organization)
			navigationStore.setSelected('organizations')
		},
		goToCatalogi() {
			catalogiStore.setCatalogiItem(this.catalogi)
			navigationStore.setSelected('catalogi')
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
		setActiveAttachment(attachment) {
			if (JSON.stringify(publicationStore.attachmentItem) === JSON.stringify(attachment)) {
				publicationStore.setAttachmentItem(false)
			} else { publicationStore.setAttachmentItem(attachment) }
		},
		setActiveDataKey(dataKey) {
			if (publicationStore.publicationDataKey === dataKey) {
				publicationStore.setPublicationDataKey(false)
			} else { publicationStore.setPublicationDataKey(dataKey) }
		},
		deleteMissingTheme(themeId) {
			this.deleteThemeLoading = true

			const newPublication = new Publication({
				...this.publication,
				themes: this.publication.themes.filter((theme) => theme !== themeId),
			})

			publicationStore.editPublication(newPublication)
				.then(() => (this.deleteThemeLoading = false))
		},
		/**
		 * Opens the folder URL in a new tab after parsing the encoded URL and converting to Nextcloud format
		 * @param {string} url - The encoded folder URL to open (e.g. "Open Registers\/Publicatie Register\/Publicatie\/123")
		 */
		openFolder(url) {
			// Parse the encoded URL by replacing escaped characters
			const decodedUrl = url.replace(/\\\//g, '/')

			// Ensure URL starts with forward slash
			const normalizedUrl = decodedUrl.startsWith('/') ? decodedUrl : '/' + decodedUrl

			// Construct the proper Nextcloud Files app URL with the normalized path
			// Use window.location.origin to get the current domain instead of hardcoding
			const nextcloudUrl = `${window.location.origin}/index.php/apps/files/files?dir=${encodeURIComponent(normalizedUrl)}`

			// Open URL in new tab
			window.open(nextcloudUrl, '_blank')
		},
		/**
		 * Opens a file in the Nextcloud Files app
		 * @param {object} file - The file object containing id, path, and other metadata
		 */
		openFile(file) {
			// Extract the directory path without the filename
			const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))

			// Remove the '/admin/files/' prefix if it exists
			const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')

			// Construct the proper Nextcloud Files app URL with file ID and openfile parameter
			const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`

			// Open URL in new tab
			window.open(filesAppUrl, '_blank')
		},
		/**
		 * Formats a file size in bytes to a human readable string
		 * @param {number} bytes - The file size in bytes
		 * @return {string} Formatted file size (e.g. "1.5 MB")
		 */
		formatFileSize(bytes) {
			if (!bytes) return '0 B'
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			let i = 0
			while (bytes >= 1024 && i < units.length - 1) {
				bytes /= 1024
				i++
			}
			return `${bytes.toFixed(1)} ${units[i]}`
		},
	},

}
</script>

<style>
h4 {
	font-weight: bold;
}

.head {
	display: flex;
	justify-content: space-between;
}

.button {
	max-height: 10px;
}

.h1 {
	display: block !important;
	font-size: 2em !important;
	margin-block-start: 0.67em !important;
	margin-block-end: 0.67em !important;
	margin-inline-start: 0px !important;
	margin-inline-end: 0px !important;
	font-weight: bold !important;
	unicode-bidi: isolate !important;
}

.dataContent {
	display: flex;
	flex-direction: column;
}

.fileLabelsContainer {
	display: inline-flex;
	gap: 3px;
}

.active.publicationDetails-actionsDelete {
	background-color: var(--color-error) !important;
}

.active.publicationDetails-actionsDelete button {
	color: #EBEBEB !important;
}

.PublicationDetail-clickable {
	cursor: pointer !important;
}

.buttonLinkContainer {
	display: flex;
	align-items: center;
}

.flex-hor {
	display: flex;
	gap: 4px;
}

.float-right {
	float: right;
}

.attachmantButtonsContainer {
	display: flex;
	gap: 10px;
}

.fullWidthButton {
	width: 100%;
	max-width: 300px;
}

.selectedFileIcon {
	color: var(--color-primary);
}
</style>
