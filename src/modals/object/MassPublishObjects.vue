<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="dialogTitle"
		:can-close="true"
		size="normal"
		class="mass-action-dialog"
		@update:open="handleDialogClose">
		<div v-if="success === null" class="publish-step">
			<div class="mode-row">
				<div class="mode-row__mode">
					<span v-for="opt in modeOptions"
						:key="opt.id"
						class="mode-row__mode-sizer"
						aria-hidden="true">{{ opt.label }}</span>
					<NcSelect v-model="selectedMode"
						:options="modeOptions"
						:clearable="false"
						:searchable="false"
						label-attribute="label"
						:aria-label-combobox="t('opencatalogi', 'Publishing mode')"
						:disabled="loading">
						<template #option="option">
							<span :title="option.title || ''"
								:class="{ 'mode-option-disabled': option.disabled }">
								{{ option.label }}
							</span>
						</template>
					</NcSelect>
				</div>
				<NcDateTimePicker v-if="mode === 'later'"
					:key="'publish-later-date'"
					class="mode-row__date"
					:value="publishDateObj"
					:label="t('opencatalogi', 'Publication date')"
					type="date"
					:min="minPublishDate"
					:disabled-date="isDateBeforeMin"
					:clearable="false"
					@input="handleDateInput"
					@update:value="handleDateInput"
					@change="handleDateInput"
					@update:modelValue="handleDateInput" />
			</div>

			<SelectedObjectsList
				hide-title
				subtitle-attribute="summary"
				:show-remove="true"
				:is-disabled="isObjectUnsupported"
				:disabled-reason="unsupportedReason" />
		</div>

		<NcNoteCard type="info">
			{{ infoText }}
		</NcNoteCard>
		<NcNoteCard v-if="alreadyPublishedCount > 0 && mode !== 'retroactive'"
			type="warning">
			{{ alreadyPublishedWarning }}
		</NcNoteCard>
		<NcNoteCard v-if="unsupportedCount > 0" type="warning">
			{{ unsupportedWarning }}
		</NcNoteCard>

		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? t('opencatalogi', 'Cancel') : t('opencatalogi', 'Close') }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="submitDisabled"
				type="primary"
				@click="publishObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Publish v-if="!loading" :size="20" />
				</template>
				{{ t('opencatalogi', 'Publish') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDateTimePicker,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'
import { schemaHasPublicationDateFields } from '../../services/schemaHelpers.js'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'MassPublishObjects',
	components: {
		NcDialog,
		NcButton,
		NcDateTimePicker,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		SelectedObjectsList,
		Publish,
		Cancel,
	},

	data() {
		return {
			selectedMode: null,
			publishDate: null,
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
			originalSelectedCount: 0,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		mode() {
			return this.selectedMode?.id || 'now'
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		today() {
			return new Date().toISOString().slice(0, 10)
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		minPublishDate() {
			const start = new Date()
			start.setHours(0, 0, 0, 0)
			return start
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		publishDateObj() {
			if (!this.publishDate) return null
			const [year, month, day] = this.publishDate.split('-').map(Number)
			return new Date(year, month - 1, day)
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		selectedObjects() {
			return objectStore.selectedObjects || []
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		anyDepublished() {
			return this.selectedObjects.some(obj => this.isDepublished(obj))
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		allDepublished() {
			return this.selectedObjects.length > 0
				&& this.selectedObjects.every(obj => this.isDepublished(obj))
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		alreadyPublishedCount() {
			return this.selectedObjects.filter(obj => this.isAlreadyPublished(obj)).length
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		alreadyPublishedWarning() {
			const count = this.alreadyPublishedCount
			if (count === 1) {
				return t('opencatalogi', '1 of the selected publications is already published and will be skipped. Its publication date will not be changed.')
			}
			return t('opencatalogi', '{count} of the selected publications are already published and will be skipped. Their publication dates will not be changed.', { count })
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		unsupportedCount() {
			return this.selectedObjects.filter(obj => !schemaHasPublicationDateFields(obj)).length
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		unsupportedWarning() {
			const count = this.unsupportedCount
			if (count === 1) {
				return t('opencatalogi', '1 of the selected publications has a schema that does not support publishing and will be skipped. Ask your IT manager for help.')
			}
			return t('opencatalogi', '{count} of the selected publications have schemas that do not support publishing and will be skipped. Ask your IT manager for help.', { count })
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		modeOptions() {
			const options = [
				{ id: 'now', label: t('opencatalogi', 'Publish now') },
				{ id: 'later', label: t('opencatalogi', 'Publish later') },
			]
			if (this.anyDepublished) {
				options.push({
					id: 'retroactive',
					label: t('opencatalogi', 'Publish retroactive'),
					disabled: !this.allDepublished,
					title: this.allDepublished
						? ''
						: t('opencatalogi', 'Only available when all selected items are depublished'),
				})
			}
			return options
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		dialogTitle() {
			const count = this.selectedObjects.length
			if (count === 1) {
				return t('opencatalogi', 'Publish publication')
			}
			return t('opencatalogi', 'Publish {count} publications', { count })
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		infoText() {
			if (this.mode === 'later') {
				return t('opencatalogi', 'The publication date will be set to the chosen date. Any existing depublication date will be removed.')
			}
			if (this.mode === 'retroactive') {
				return t('opencatalogi', 'The depublish date will be removed. The publish date will not change.')
			}
			return t('opencatalogi', 'Publications will be published with today\'s date. Any existing depublication date will be removed.')
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		submitDisabled() {
			if (this.loading) return true
			if (this.selectedObjects.length === 0) return true
			if (this.selectedMode?.disabled) return true
			if (this.mode === 'later' && !this.publishDate) return true
			// Nothing to do if every selected item would be skipped.
			if (this.unsupportedCount === this.selectedObjects.length) {
				return true
			}
			if (this.mode !== 'retroactive'
				&& this.alreadyPublishedCount === this.selectedObjects.length) {
				return true
			}
			return false
		},
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		successMessage() {
			const plural = this.originalSelectedCount > 1
			if (this.mode === 'retroactive') {
				return plural
					? t('opencatalogi', 'Publications successfully published retroactive')
					: t('opencatalogi', 'Publication successfully published retroactive')
			}
			return plural
				? t('opencatalogi', 'Publications successfully published')
				: t('opencatalogi', 'Publication successfully published')
		},
	},

	watch: {
		selectedObjects: {
			deep: true,
			/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
			handler() {
				// If the user removed items so retroactive is no longer valid,
				// reset the mode to the default.
				if (this.mode === 'retroactive'
					&& (!this.anyDepublished || !this.allDepublished)) {
					this.selectedMode = this.modeOptions[0]
				}
			},
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
	mounted() {
		this.originalSelectedCount = this.selectedObjects.length
		this.selectedMode = this.modeOptions[0]
	},

	methods: {
		/**
		 * Normalize a date-like value to a YYYY-MM-DD string for comparison, or null.
		 * Absent/empty values return null. publicatiedatum is schema-declared as
		 * format: 'date', so values are YYYY-MM-DD strings; lexicographic comparison
		 * matches chronological order.
		 *
		 * @param {unknown} value - The raw date value from the object
		 * @return {string|null} The normalized YYYY-MM-DD string or null
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		normalizeDate(value) {
			if (value == null || value === '') return null
			return String(value).slice(0, 10)
		},

		/**
		 * Determine if an object is currently published (live): publicatiedatum is in
		 * the past or today, and is more recent than any depublicatiedatum.
		 * A future publicatiedatum means the publication is scheduled but not yet live,
		 * so it is not considered "already published" and can be re-targeted to now.
		 *
		 * @param {object} obj - The publication object
		 * @return {boolean} true if currently published
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		isAlreadyPublished(obj) {
			const pub = this.normalizeDate(obj?.publicatiedatum)
			if (!pub) return false
			// Future publication date → scheduled but not yet live; allow re-publishing.
			if (pub > this.today) return false
			const depub = this.normalizeDate(obj?.depublicatiedatum)
			if (!depub) return true
			return pub > depub
		},

		/**
		 * Determine if an object is currently depublished: depublish date is the
		 * most recent, or the only one set. Drives the 'Publish retroactive' option.
		 *
		 * @param {object} obj - The publication object
		 * @return {boolean} true if currently depublished
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		isDepublished(obj) {
			const depub = this.normalizeDate(obj?.depublicatiedatum)
			if (!depub) return false
			const pub = this.normalizeDate(obj?.publicatiedatum)
			if (!pub) return true
			// Tiebreaker: depublish wins on equal dates.
			return depub >= pub
		},

		/**
		 * Predicate passed to SelectedObjectsList: items whose schema does not
		 * declare both publicatiedatum and depublicatiedatum render at reduced
		 * opacity and are skipped during the publish loop.
		 *
		 * @param {object} obj - The publication object.
		 * @return {boolean} true when the object will be skipped.
		 */
		isObjectUnsupported(obj) {
			return !schemaHasPublicationDateFields(obj)
		},

		/**
		 * Tooltip text explaining why an unsupported item is greyed out.
		 *
		 * @return {string} The reason.
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		unsupportedReason() {
			return t('opencatalogi', 'This schema does not support publishing. Ask your IT manager for help.')
		},

		/**
		 * Predicate for NcDateTimePicker's :disabled-date prop. Returns true for any
		 * date strictly before `minPublishDate` so the picker greys them out.
		 *
		 * @param {Date} date - A day passed by the picker
		 * @return {boolean} true if the date should be unselectable
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		isDateBeforeMin(date) {
			if (!(date instanceof Date) || Number.isNaN(date.getTime())) return false
			const d = new Date(date)
			d.setHours(0, 0, 0, 0)
			return d.getTime() < this.minPublishDate.getTime()
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		handleDateInput(value) {
			if (!value) {
				this.publishDate = null
				return
			}
			const date = value instanceof Date ? value : new Date(value)
			if (Number.isNaN(date.getTime())) {
				this.publishDate = null
				return
			}
			// Active guard: reject any date before the minimum regardless of what
			// the picker allowed through. submitDisabled then stays true because
			// publishDate remains null.
			const dayStart = new Date(date)
			dayStart.setHours(0, 0, 0, 0)
			if (dayStart.getTime() < this.minPublishDate.getTime()) {
				this.publishDate = null
				return
			}
			const year = date.getFullYear()
			const month = String(date.getMonth() + 1).padStart(2, '0')
			const day = String(date.getDate()).padStart(2, '0')
			this.publishDate = `${year}-${month}-${day}`
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		closeDialog() {
			if (this.closeModalTimeout) {
				clearTimeout(this.closeModalTimeout)
				this.closeModalTimeout = null
			}
			navigationStore.setDialog(false)
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeDialog()
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		async publishObjects() {
			if (this.selectedMode?.disabled) return
			this.loading = true
			this.error = false

			const targetDate = this.mode === 'later' ? this.publishDate : this.today
			const objectsToProcess = [...this.selectedObjects]
			const successful = []
			const failed = []

			for (const obj of objectsToProcess) {
				try {
					// Skip publications whose schema lacks the publication date
					// fields the publish flow writes to.
					if (!schemaHasPublicationDateFields(obj)) {
						continue
					}
					// Skip already-published items in publish-now / publish-later modes:
					// their publicatiedatum must not be overwritten.
					if (this.mode !== 'retroactive' && this.isAlreadyPublished(obj)) {
						continue
					}

					const clone = JSON.parse(JSON.stringify(obj))
					if (this.mode === 'retroactive') {
						// publicatiedatum is left unchanged on purpose
						clone.depublicatiedatum = null
					} else {
						clone.publicatiedatum = targetDate
						clone.depublicatiedatum = null
					}

					const register = clone['@self']?.register ?? clone.register
					const schema = clone['@self']?.schema ?? clone.schema

					await objectStore.saveObject(clone, { register, schema })
					successful.push(obj)
				} catch (err) {
					console.error('Error publishing object:', err)
					failed.push({ object: obj, error: err })
				}
			}

			if (successful.length > 0) {
				catalogStore.fetchPublications()
			}

			if (failed.length === 0 && successful.length > 0) {
				this.success = true
				this.closeModalTimeout = setTimeout(() => {
					this.closeDialog()
				}, 2000)
			} else if (failed.length > 0) {
				this.error = t('opencatalogi', 'Failed to publish {count} object(s)', { count: failed.length })
				if (successful.length > 0) {
					this.success = true
				}
			}

			this.loading = false
		},
	},
}
</script>

<style scoped>
.publish-step {
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.mode-row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	flex-wrap: wrap;
}

.mode-row__mode {
	display: inline-grid;
	flex: 0 0 auto;
	min-width: 170px;
}

.mode-row__mode-sizer {
	grid-area: 1 / 1;
	visibility: hidden;
	pointer-events: none;
	white-space: nowrap;
	padding: 0 40px 0 8px;
	height: 0;
	overflow: hidden;
}

.mode-row__mode :deep(.v-select) {
	grid-area: 1 / 1;
	width: 100%;
}

.mode-row__date {
	flex: 0 0 auto;
}

.mode-option-disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>

<style>
.mass-action-dialog {
	z-index: 10000 !important;
}
</style>
