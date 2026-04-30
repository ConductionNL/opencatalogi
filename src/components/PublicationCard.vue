<template>
	<div
		class="publication-card"
		:class="[`publication-card--${status}`, { 'publication-card--selected': selected }]"
		@click="$emit('click', object)">

		<div v-if="selectable" class="publication-card__checkbox" @click.stop>
			<NcCheckboxRadioSwitch
				:checked="selected"
				@update:checked="$emit('select', object)" />
		</div>

		<div class="publication-card__content">
			<div class="publication-card__header">
				<div class="publication-card__title-row">
					<PublishedIcon :object="object" :size="16" />
					<h3 class="publication-card__title">
						{{ title }}
					</h3>
				</div>
				<p v-if="summary" class="publication-card__summary">
					{{ truncatedSummary }}
				</p>
			</div>

			<div class="publication-card__metadata">
				<div class="publication-card__status">
					<template v-if="status === 'concept'">
						<span v-if="object.publicatiedatum">
							{{ t('opencatalogi', 'Scheduled for') }} {{ formatDate(object.publicatiedatum) }}
						</span>
						<span v-else>{{ t('opencatalogi', 'Concept') }}</span>
					</template>
					<template v-else-if="status === 'published'">
						{{ t('opencatalogi', 'Published on') }} {{ formatDate(object.publicatiedatum) }}
					</template>
					<template v-else>
						{{ t('opencatalogi', 'Depublished on') }} {{ formatDate(object.depublicatiedatum) }}
					</template>
				</div>

				<div class="publication-card__files">
					<Paperclip :size="14" />
					<NcCounterBubble :count="filesCount" />
				</div>
			</div>
		</div>

		<div v-if="$scopedSlots.actions" class="publication-card__actions" @click.stop>
			<slot name="actions" :object="object" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcCounterBubble } from '@nextcloud/vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import { getPublicationStatus } from '../services/publicationStatus.js'
import PublishedIcon from './PublishedIcon.vue'
import getValidISOstring from '../services/getValidISOstring.js'

export default {
	name: 'PublicationCard',
	components: {
		NcCheckboxRadioSwitch,
		NcCounterBubble,
		Paperclip,
		PublishedIcon,
	},
	props: {
		object: {
			type: Object,
			required: true,
		},
		selected: {
			type: Boolean,
			default: false,
		},
		selectable: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		status() {
			return getPublicationStatus(this.object)
		},
		title() {
			return this.object['@self']?.name || this.object.title || this.object.name || this.object.id || '—'
		},
		summary() {
			return this.object.summary || null
		},
		truncatedSummary() {
			if (!this.summary) return null
			if (this.summary.length > 120) return this.summary.substring(0, 120) + '...'
			return this.summary
		},
		filesCount() {
			const countFromSelf = this.object?.['@self']?.filesCount
				|| this.object?.['@self']?.attachmentsCount
				|| this.object?.['@self']?.attachmentCount
			if (typeof countFromSelf === 'number') return countFromSelf
			const filesProp = this.object?.['@self']?.files
			if (Array.isArray(filesProp)) return filesProp.length
			if (filesProp) return 1
			return 0
		},
	},
	methods: {
		t,
		formatDate(dateString) {
			if (!dateString) return 'N/A'
			if (!getValidISOstring(dateString)) return dateString
			return new Date(dateString).toLocaleString()
		},
	},
}
</script>

<style scoped>
.publication-card {
	display: flex;
	gap: 12px;
	padding: 16px 16px 16px 13px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	cursor: pointer;
	transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.publication-card:hover {
	box-shadow: 0 2px 8px var(--color-box-shadow);
}

.publication-card--published {
	border-left-color: var(--color-success);
}

.publication-card--concept {
	border-left-color: var(--color-warning);
}

.publication-card--depublished {
	border-left-color: var(--color-error);
}

.publication-card--selected {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.publication-card__checkbox {
	flex-shrink: 0;
	padding-top: 2px;
}

.publication-card__content {
	flex: 1;
	min-width: 0;
}

.publication-card__header {
	margin-bottom: 10px;
}

.publication-card__title-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.publication-card__title {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
	line-height: 1.3;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.publication-card__summary {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.publication-card__metadata {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.publication-card__files {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.publication-card__actions {
	flex-shrink: 0;
}
</style>
