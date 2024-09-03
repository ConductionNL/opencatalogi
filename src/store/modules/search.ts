/* eslint-disable no-console */
import { defineStore } from 'pinia'

export const useSearchStore = defineStore('search', {
	state: () => ({
		search: '',
		metadata: {},
		catalogi: {},
		searchResults: '',
		searchError: '',
	}),
	actions: {
		setSearch(search: string) {
			this.search = search
			console.log('Active search set to ' + search)
		},
		setSearchResults(searchResults: string[]) {
			this.searchResults = searchResults
			console.log('Active search set to ' + searchResults)
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		getSearchResults() {
			const enabledMetadataIds = Object.entries(this.metadata)
				// eslint-disable-next-line @typescript-eslint/no-unused-vars
				.filter(([_, value]) => value === true)
				.map((metadata) => metadata[0])

			const enabledCatalogiIds = Object.entries(this.catalogi)
				// eslint-disable-next-line @typescript-eslint/no-unused-vars
				.filter(([_, value]) => value === true)
				.map((catalogi) => catalogi[0])

			// @ts-expect-error -- for some reason it gives errors in TS even doh it works
			const searchParams = new URLSearchParams({
				...(this.search && { _search: this.search }),
				...(enabledMetadataIds[0] && { meta_data: enabledMetadataIds }),
				...(enabledCatalogiIds[0] && { catalogi: enabledCatalogiIds }),
			}).toString()

			fetch('/index.php/apps/opencatalogi/api/search?' + searchParams,
				{
					method: 'GET',
				},
			)
				.then((response) => {
					response.json().then((data) => {
						if (data?.code === 403 && data?.message) {
							this.searchError = data.message
						} else {
							this.searchError = '' // Clear any previous errors
						}
						this.searchResults = data
					},
					)
				},
				)
				.catch(
					(err) => {
						this.searchError = err.message || 'An error occurred'
						console.error(err.message ?? err)
					},
				)
		},
		clearSearch() {
			this.search = ''
			this.searchError = ''
		},
	},
},
)