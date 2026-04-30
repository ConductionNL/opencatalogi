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
import { isPublished, isDepublished } from '../services/publicationStatus.js'

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
		 * @type {object}
		 */
		object: {
			type: Object,
			required: true,
		},
		/**
		 * Size of the icon
		 * @type {number}
		 */
		size: {
			type: Number,
			default: 20,
		},
		/**
		 * Additional CSS class to apply to the icon
		 * @type {string}
		 */
		iconClass: {
			type: String,
			default: '',
		},
		/**
		 * Custom tooltip for published state
		 * @type {string}
		 */
		publishedTooltip: {
			type: String,
			default: 'Published: This publication is live and publicly available',
		},
		/**
		 * Custom tooltip for draft state
		 * @type {string}
		 */
		draftTooltip: {
			type: String,
			default: 'Draft: This publication is not yet published',
		},
		/**
		 * Custom tooltip for depublished state
		 * @type {string}
		 */
		depublishedTooltip: {
			type: String,
			default: 'Depublished: This publication has been withdrawn from public access',
		},
	},
	computed: {
		isPublished() { return isPublished(this.object) },
		isDepublished() { return isDepublished(this.object) },
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
