/**
 * OrganizationIndex.vue
 * Component for displaying and managing organizations using GenericObjectTable
 * @category Views
 * @package opencatalogi
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/opencatalogi
 */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<GenericObjectTable
		object-type="organization"
		object-type-plural="organizations"
		:title="t('opencatalogi', 'Organizations')"
		:description="t('opencatalogi', 'Manage your organizations and their configurations')"
		:empty-icon="OfficeBuildingOutline"
		:card-icon="OfficeBuildingOutline"
		:properties="organizationProperties"
		:object-actions="organizationObjectActions"
		:mass-actions="organizationMassActions"
		:actions="organizationActions"
		:add-action="addOrganizationAction"
		:help-url="'https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/organisaties'"
		@mounted="onMounted" />
</template>

<script>
import GenericObjectTable from '../../components/GenericObjectTable.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import PublishIcon from 'vue-material-design-icons/Publish.vue'
import PublishOffIcon from 'vue-material-design-icons/PublishOff.vue'

export default {
	name: 'OrganizationIndex',
	components: {
		GenericObjectTable,
	},
	data() {
		return {
			organizationProperties: [
				{
					id: 'name',
					label: 'Name',
					key: 'name',
					sortable: true,
					searchable: true,
				},
				{
					id: 'website',
					label: 'Website',
					key: 'website',
					sortable: true,
					searchable: true,
				},
				{
					id: 'summary',
					label: 'Summary',
					key: 'summary',
					sortable: false,
					searchable: true,
				},
				{
					id: 'oin',
					label: 'OIN',
					key: 'oin',
					sortable: true,
					searchable: true,
				},
				{
					id: 'tooi',
					label: 'TOOI',
					key: 'tooi',
					sortable: true,
					searchable: true,
				},
				{
					id: 'rsin',
					label: 'RSIN',
					key: 'rsin',
					sortable: true,
					searchable: true,
				},
				{
					id: 'updatedAt',
					label: 'Last Updated',
					key: 'updatedAt',
					sortable: true,
					searchable: false,
				},
			],
			organizationObjectActions: [
				{
					id: 'view',
					label: 'View',
					icon: Eye,
					handler: (organization) => {
						objectStore.setActiveObject('organization', organization)
						navigationStore.setModal('viewOrganization')
					},
				},
				{
					id: 'edit',
					label: 'Edit',
					icon: Pencil,
					handler: (organization) => {
						objectStore.setActiveObject('organization', organization)
						navigationStore.setModal('organization')
					},
				},
				{
					id: 'copy',
					label: 'Copy',
					icon: ContentCopy,
					handler: (organization) => {
						objectStore.setActiveObject('organization', organization)
						navigationStore.setDialog('copyObject', {
							objectType: 'organization',
							dialogTitle: 'Organization',
						})
					},
				},
				{
					id: 'delete',
					label: 'Delete',
					icon: TrashCanOutline,
					handler: (organization) => {
						objectStore.setActiveObject('organization', organization)
						navigationStore.setDialog('deleteObject', {
							objectType: 'organization',
							dialogTitle: 'Organization',
						})
					},
				},
			],
			organizationMassActions: [
				{
					id: 'massDelete',
					label: 'Delete Selected',
					icon: Delete,
					handler: () => {
						navigationStore.setDialog('massDeleteObjects', {
							objectType: 'organization',
							dialogTitle: 'Organizations',
						})
					},
				},
				{
					id: 'massPublish',
					label: 'Publish Selected',
					icon: PublishIcon,
					handler: () => {
						navigationStore.setDialog('massPublishObjects', {
							objectType: 'organization',
							dialogTitle: 'Organizations',
						})
					},
				},
				{
					id: 'massDepublish',
					label: 'Depublish Selected',
					icon: PublishOffIcon,
					handler: () => {
						navigationStore.setDialog('massDepublishObjects', {
							objectType: 'organization',
							dialogTitle: 'Organizations',
						})
					},
				},
			],
			organizationActions: [
				{
					id: 'add',
					label: 'Add Organization',
					icon: Plus,
					primary: true,
					handler: () => {
						objectStore.clearActiveObject('organization')
						navigationStore.setModal('organization')
					},
				},
				{
					id: 'refresh',
					label: 'Refresh',
					icon: Refresh,
					handler: () => {
						objectStore.fetchCollection('organization')
					},
					disabled: () => objectStore.isLoading('organization'),
				},
				{
					id: 'help',
					label: 'Help',
					icon: HelpCircleOutline,
					handler: () => {
						window.open('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/organisaties', '_blank')
					},
				},
			],
			addOrganizationAction: {
				id: 'add',
				label: 'Add Organization',
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject('organization')
					navigationStore.setModal('organization')
				},
			},
		}
	},
	methods: {
		onMounted() {
			console.info('OrganizationIndex mounted, fetching organizations...')
			objectStore.fetchCollection('organization')
		},
	},
}
</script>
