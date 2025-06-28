/**
 * @file PublishedIcon.vue
 * @module Components
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<template>
	<ListBoxOutline v-if="isPublished"
		v-tooltip="publishedTooltip"
		:size="size"
		:class="['published-icon', iconClass]" />
	<AlertOutline v-else-if="isDepublished"
		v-tooltip="depublishedTooltip"
		:size="size"
		:class="['depublished-icon', iconClass]" />
	<Pencil v-else
		v-tooltip="draftTooltip"
		:size="size"
		:class="['unpublished-icon', iconClass]" />
</template>

<script>
import ListBoxOutline from 'vue-material-design-icons/ListBoxOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'

export default {
	name: 'PublishedIcon',
	components: {
		ListBoxOutline,
		Pencil,
		AlertOutline,
	},
	props: {
		/**
		 * The object to check publication status for
		 * @type {Object}
		 */
		object: {
			type: Object,
			required: true,
		},
		/**
		 * Size of the icon
		 * @type {Number}
		 */
		size: {
			type: Number,
			default: 20,
		},
		/**
		 * Additional CSS class to apply to the icon
		 * @type {String}
		 */
		iconClass: {
			type: String,
			default: '',
		},
		/**
		 * Custom tooltip for published state
		 * @type {String}
		 */
		publishedTooltip: {
			type: String,
			default: 'Published: This publication is live and publicly available',
		},
		/**
		 * Custom tooltip for draft state
		 * @type {String}
		 */
		draftTooltip: {
			type: String,
			default: 'Draft: This publication is not yet published',
		},
		/**
		 * Custom tooltip for depublished state
		 * @type {String}
		 */
		depublishedTooltip: {
			type: String,
			default: 'Depublished: This publication has been withdrawn from public access',
		},
	},
	computed: {
		icon() {
			const published = this.object?.['@self']?.published
			const depublished = this.object?.['@self']?.depublished
			
			if (published !== null && published !== undefined && (depublished === null || depublished === undefined)) {
				return 'ListBoxOutline'
			}
			if (depublished !== null && depublished !== undefined) {
				return 'AlertOutline'
			}
			return 'Pencil'
		},
		iconColor() {
			const published = this.object?.['@self']?.published
			const depublished = this.object?.['@self']?.depublished
			
			if (published !== null && published !== undefined && (depublished === null || depublished === undefined)) {
				return 'published-icon'
			}
			if (depublished !== null && depublished !== undefined) {
				return 'depublished-icon'
			}
			return 'draft-icon'
		},
		tooltip() {
			const published = this.object?.['@self']?.published
			const depublished = this.object?.['@self']?.depublished
			
			if (published !== null && published !== undefined && (depublished === null || depublished === undefined)) {
				return this.publishedTooltip
			}
			if (depublished !== null && depublished !== undefined) {
				return this.depublishedTooltip
			}
			return this.draftTooltip
		},
		/**
		 * Check if the object is published
		 * @return {Boolean} True if the object is published
		 */
		isPublished() {
			return !!(this.object?.['@self']?.published && !this.object?.['@self']?.depublished)
		},
		/**
		 * Check if the object is depublished
		 * @return {Boolean} True if the object is depublished
		 */
		isDepublished() {
			return !!(this.object?.['@self']?.depublished)
		},
	},
}
</script>

<style scoped>
/* Publication status icon colors */
.published-icon {
	color: var(--color-success);
}

.unpublished-icon {
	color: var(--color-warning);
}

.depublished-icon {
	color: var(--color-error);
}
</style> 