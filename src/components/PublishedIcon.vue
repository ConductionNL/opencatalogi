<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
/** * @file PublishedIcon.vue * @module Components * @author Your Name * @copyright
2024 Your Organization * @license EUPL-1.2 * @version 1.0.0 */

<template>
	<ListBoxOutline
		v-if="isPublished"
		:title="publishedTooltip"
		:size="size"
		class="published-icon"
		:class="[iconClass]" />
	<AlertOutline
		v-else-if="isDepublished"
		:title="depublishedTooltip"
		:size="size"
		class="depublished-icon"
		:class="[iconClass]" />
	<CalendarClock
		v-else-if="status === 'scheduled'"
		:title="t('opencatalogi', 'Scheduled: This publication goes live on its publication date')"
		:size="size"
		class="scheduled-icon"
		:class="[iconClass]" />
	<AlertCircleOutline
		v-else-if="status === 'invalid'"
		:title="t('opencatalogi', 'Never goes live: the depublication date is on or before the publication date')"
		:size="size"
		class="depublished-icon"
		:class="[iconClass]" />
	<Pencil
		v-else
		:title="draftTooltip"
		:size="size"
		class="unpublished-icon"
		:class="[iconClass]" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import ListBoxOutline from 'vue-material-design-icons/ListBoxOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { getPublicationStatus } from '../services/publicationStatus.js'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'PublishedIcon',
	components: {
		ListBoxOutline,
		Pencil,
		AlertOutline,
		AlertCircleOutline,
		CalendarClock,
	},

	props: {
		/**
		 * The object to check publication status for
		 *
		 * @type {object}
		 */
		object: {
			type: Object,
			required: true,
		},

		/**
		 * Size of the icon
		 *
		 * @type {number}
		 */
		size: {
			type: Number,
			default: 20,
		},

		/**
		 * Additional CSS class to apply to the icon
		 *
		 * @type {string}
		 */
		iconClass: {
			type: String,
			default: '',
		},

		/**
		 * Custom tooltip for published state
		 *
		 * @type {string}
		 */
		publishedTooltip: {
			type: String,
			default: 'Published: This publication is live and publicly available',
		},

		/**
		 * Custom tooltip for draft state
		 *
		 * @type {string}
		 */
		draftTooltip: {
			type: String,
			default: 'Draft: This publication is not yet published',
		},

		/**
		 * Custom tooltip for depublished state
		 *
		 * @type {string}
		 */
		depublishedTooltip: {
			type: String,
			default:
				'Depublished: This publication has been withdrawn from public access',
		},
	},

	computed: {
		/** @spec openspec/specs/publications/spec.md */
		status() {
			return getPublicationStatus(this.object)
		},

		isPublished() {
			return this.status === 'published'
		},

		isDepublished() {
			return this.status === 'depublished'
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
/* Publication status icon colors */
.published-icon {
	color: var(--color-element-success);
}

.unpublished-icon {
	color: var(--color-element-warning);
}

.depublished-icon {
	color: var(--color-element-error);
}

.scheduled-icon {
	color: var(--color-primary-element);
}
</style>
