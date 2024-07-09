<script setup>
import { store } from '../../store.js'
</script>

<template>
	<div class="detailContainer">
		<div v-if="!loading" id="app-content">
			<!-- app-content-wrapper is optional, only use if app-content-list  -->
			<div>
				<h1 class="h1">
					{{ publication.title }}
				</h1>
				<div>
					<div>
						<h4>Beschrijving:</h4>
						<span>{{ publication.description }}</span>
					</div>
					<div>
						<h4>Catalogi:</h4>
						<span>{{ publication.catalogi }}</span>
					</div>
					<div>
						<h4>Metadata:</h4>
						<span>{{ publication.metaData }}</span>
					</div>
					<div>
						<h4>Data:</h4>
						<div class="dataContent">
							<span v-for="(value, name, i) in publication.data" :key="`${name, value}${i}`">{{
								`${name}: ${value}`
							}}</span>
						</div>
					</div>
				</div>
			</div>
			<NcButton type="primary" @click="store.setModal('publicationEdit')">
				Publicatie bewerken
			</NcButton>
		</div>
		<NcLoadingIcon
			v-if="loading"
			:size="100"
			appearance="dark"
			name="Publicatie details aan het laden" />
	</div>
</template>

<script>
import { NcLoadingIcon, NcButton } from '@nextcloud/vue'

export default {
	name: 'PublicationDetail',
	components: {
		NcLoadingIcon,
		NcButton,
	},
	props: {
		publicationId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			publication: [],
			loading: false,
		}
	},
	watch: {
		publicationId: {
			handler(publicationId) {
				this.fetchData(publicationId)
			},
			deep: true,
		},
	},
	mounted() {
		this.fetchData(this.publicationId)
	},
	methods: {
		fetchData(id) {
			this.loading = true
			fetch(`/index.php/apps/opencatalog/api/publications/${id}`, {
				method: 'GET',
			})
				.then((response) => {
					response.json().then((data) => {
						this.publication = data
						// this.oldZaakId = id
					})
					this.loading = false
				})
				.catch((err) => {
					console.error(err)
					// this.oldZaakId = id
					this.loading = false
				})
		},
	},
}
</script>

<style>
h4 {
  font-weight: bold;
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

.tabContainer > * ul > li {
  display: flex;
  flex: 1;
}

.tabContainer > * ul > li:hover {
  background-color: var(--color-background-hover);
}

.tabContainer > * ul > li > a {
  flex: 1;
  text-align: center;
}

.tabContainer > * ul > li > .active {
  background: transparent !important;
  color: var(--color-main-text) !important;
  border-bottom: var(--default-grid-baseline) solid var(--color-primary-element) !important;
}

.tabContainer > * ul {
  display: flex;
  margin: 10px 8px 0 8px;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-border);
}

.tabPanel {
  padding: 20px 10px;
  min-height: 100%;
  max-height: 100%;
  height: 100%;
  overflow: auto;
}
</style>