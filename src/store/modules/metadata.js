/* eslint-disable no-console */
import { defineStore } from 'pinia'

export const useMetadataStore = defineStore('metadata', {
	state: () => ({
		metaDataItem: false,
		metaDataList: [],
		metadataDataKey: false,
	}),
	actions: {
		setMetaDataItem(metaDataItem) {
		// To prevent forms etc from braking we alway use a default/skeleton object
			const metaDataDefault = {
				name: '',
				version: '',
				summary: '',
				description: '',
				properties: {},
			}
			this.metaDataItem = { ...metaDataDefault, ...metaDataItem }

			// for backward compatibility
			if (typeof this.metaDataItem.properties === 'string') {
				this.metaDataItem.properties = JSON.parse(this.metaDataItem.properties)
			}

			console.log('Active metadata object set to ' + metaDataItem.id)
		},
		setMetaDataList(metaDataList) {
			this.metaDataList = metaDataList
			console.log('Active metadata lest set')
		},
		refreshMetaDataList() { // @todo this might belong in a service?
			fetch(
				'/index.php/apps/opencatalogi/api/metadata',
				{
					method: 'GET',
				},
			)
				.then((response) => {
					response.json().then((data) => {
						this.metaDataList = data
						return data
					})
				})
				.catch((err) => {
					console.error(err)
					return err
				})
		},
		setMetadataDataKey(metadataDataKey) {
			this.metadataDataKey = metadataDataKey
			console.log('Active metadata data key set to ' + metadataDataKey)
		},
		getMetadataPropertyKeys(property) {
			const defaultKeys = {
				type: '',
				description: '',
				format: '',
				maxDate: '',
				required: false,
				default: false,
				$ref: '', // $ref should probably be removed as it is not mentioned in the schema
				cascadeDelete: false,
				exclusiveMinimum: 0,
			}

			const propertyKeys = this.metaDataItem.properties[property]

			return { ...defaultKeys, ...propertyKeys }
		},
	},
})
