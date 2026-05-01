<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="dialogTitle"
		:can-close="true"
		size="normal"
		class="mass-action-dialog"
		@update:open="handleDialogClose">
		<div v-if="success === null" class="depublish-step">
			<div class="mode-row">
				<NcSelect v-model="selectedMode"
					class="mode-row__mode"
					:options="modeOptions"
					:clearable="false"
					:searchable="false"
					label-attribute="label"
					:aria-label-combobox="t('opencatalogi', 'Depublishing mode')"
					:disabled="loading">
					<template #option="option">
						<span>{{ option.label }}</span>
					</template>
				</NcSelect>
				<NcDateTimePicker v-if="mode === 'later'"
					:key="'depublish-later-date'"
					class="mode-row__date"
					:value="depublishDateObj"
					:label="t('opencatalogi', 'Depublication date')"
					type="date"
					:min="minDepublishDate"
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
		<NcNoteCard v-if="alreadyDepublishedCount > 0" type="warning">
			{{ alreadyDepublishedWarning }}
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
				type="error"
				@click="depublishObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<PublishOff v-if="!loading" :size="20" />
				</template>
				{{ t('opencatalogi', 'Depublish') }}
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
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'
import { schemaHasPublicationDateFields } from '../../services/schemaHelpers.js'

export default {
	name: 'MassDepublishObjects',
	components: {
		NcDialog,
		NcButton,
		NcDateTimePicker,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		SelectedObjectsList,
		PublishOff,
		Cancel,
	},

	data() {
		return {
			selectedMode: null,
			depublishDate: null,
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
			originalSelectedCount: 0,
		}
	},

	computed: {
		mode() {
			return this.selectedMode?.id || 'now'
		},
		today() {
			return new Date().toISOString().slice(0, 10)
		},
		minDepublishDate() {
			const start = new Date()
			start.setHours(0, 0, 0, 0)
			return start
		},
		depublishDateObj() {
			if (!this.depublishDate) return null
			const [year, month, day] = this.depublishDate.split('-').map(Number)
			return new Date(year, month - 1, day)
		},
		selectedObjects() {
			return objectStore.selectedObjects || []
		},
		alreadyDepublishedCount() {
			return this.selectedObjects.filter(obj => this.isDepublished(obj)).length
		},
		alreadyDepublishedWarning() {
			const count = this.alreadyDepublishedCount
			if (count === 1) {
				return t('opencatalogi', '1 of the selected publications is already depublished and will be skipped. Its depublication date will not be changed.')
			}
			return t('opencatalogi', '{count} of the selected publications are already depublished and will be skipped. Their depublication dates will not be changed.', { count })
		},
		unsupportedCount() {
			return this.selectedObjects.filter(obj => !schemaHasPublicationDateFields(obj)).length
		},
		unsupportedWarning() {
			const count = this.unsupportedCount
			if (count === 1) {
				return t('opencatalogi', '1 of the selected publications has a schema that does not support depublishing and will be skipped. Ask your IT manager for help.')
			}
			return t('opencatalogi', '{count} of the selected publications have schemas that do not support depublishing and will be skipped. Ask your IT manager for help.', { count })
		},
		modeOptions() {
			return [
				{ id: 'now', label: t('opencatalogi', 'Depublish now') },
				{ id: 'later', label: t('opencatalogi', 'Depublish later') },
			]
		},
		dialogTitle() {
			const count = this.selectedObjects.length
			if (count === 1) {
				return t('opencatalogi', 'Depublish publication')
			}
			return t('opencatalogi', 'Depublish {count} publications', { count })
		},
		infoText() {
			if (this.mode === 'later') {
				return t('opencatalogi', 'The depublication date will be set to the chosen date. The publication date will remain unchanged.')
			}
			return t('opencatalogi', 'Publications will be depublished with today\'s date. The publication date will remain unchanged.')
		},
		submitDisabled() {
			if (this.loading) return true
			if (this.selectedObjects.length === 0) return true
			if (this.mode === 'later' && !this.depublishDate) return true
			// Nothing to do if every selected item would be skipped.
			if (this.unsupportedCount === this.selectedObjects.length) return true
			if (this.alreadyDepublishedCount === this.selectedObjects.length) return true
			return false
		},
		successMessage() {
			const plural = this.originalSelectedCount > 1
			return plural
				? t('opencatalogi', 'Publications successfully depublished')
				: t('opencatalogi', 'Publication successfully depublished')
		},
	},

	mounted() {
		this.originalSelectedCount = this.selectedObjects.length
		this.selectedMode = this.modeOptions[0]
	},

	methods: {
		/**
		 * Normalize a date-like value to a YYYY-MM-DD string for comparison, or null.
		 * Absent/empty values return null. depublicatiedatum/publicatiedatum are
		 * schema-declared as format: 'date', so values are YYYY-MM-DD strings;
		 * lexicographic comparison matches chronological order.
		 *
		 * @param {unknown} value - The raw date value from the object
		 * @return {string|null} The normalized YYYY-MM-DD string or null
		 */
		normalizeDate(value) {
			if (value == null || value === '') return null
			return String(value).slice(0, 10)
		},

		/**
		 * Determine if an object is currently depublished: depublish date is set,
		 * has already passed (today or earlier), and is the most recent of
		 * publicatiedatum/depublicatiedatum. A future depublicatiedatum means the
		 * item is *scheduled* to be depublished but is still published today, so
		 * it should NOT be treated as depublished here — the user must be able to
		 * reschedule or depublish it immediately.
		 *
		 * Tiebreaker on equal dates: depublish wins.
		 *
		 * @param {object} obj - The publication object
		 * @return {boolean} true if currently depublished
		 */
		isDepublished(obj) {
			const depub = this.normalizeDate(obj?.depublicatiedatum)
			if (!depub) return false
			if (depub > this.today) return false
			const pub = this.normalizeDate(obj?.publicatiedatum)
			if (!pub) return true
			return depub >= pub
		},

		/**
		 * Predicate passed to SelectedObjectsList: items whose schema does not
		 * declare both publicatiedatum and depublicatiedatum render at reduced
		 * opacity and are skipped during the depublish loop.
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
		unsupportedReason() {
			return t('opencatalogi', 'This schema does not support depublishing. Ask your IT manager for help.')
		},

		/**
		 * Predicate for NcDateTimePicker's :disabled-date prop. Returns true for any
		 * date strictly before `minDepublishDate` so the picker greys them out.
		 *
		 * @param {Date} date - A day passed by the picker
		 * @return {boolean} true if the date should be unselectable
		 */
		isDateBeforeMin(date) {
			if (!(date instanceof Date) || Number.isNaN(date.getTime())) return false
			const d = new Date(date)
			d.setHours(0, 0, 0, 0)
			return d.getTime() < this.minDepublishDate.getTime()
		},

		handleDateInput(value) {
			if (!value) {
				this.depublishDate = null
				return
			}
			const date = value instanceof Date ? value : new Date(value)
			if (Number.isNaN(date.getTime())) {
				this.depublishDate = null
				return
			}
			// Active guard: reject any date before the minimum regardless of what
			// the picker allowed through. submitDisabled then stays true because
			// depublishDate remains null.
			const dayStart = new Date(date)
			dayStart.setHours(0, 0, 0, 0)
			if (dayStart.getTime() < this.minDepublishDate.getTime()) {
				this.depublishDate = null
				return
			}
			const year = date.getFullYear()
			const month = String(date.getMonth() + 1).padStart(2, '0')
			const day = String(date.getDate()).padStart(2, '0')
			this.depublishDate = `${year}-${month}-${day}`
		},

		closeDialog() {
			if (this.closeModalTimeout) {
				clearTimeout(this.closeModalTimeout)
				this.closeModalTimeout = null
			}
			navigationStore.setDialog(false)
		},

		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeDialog()
			}
		},

		async depublishObjects() {
			this.loading = true
			this.error = false

			const targetDate = this.mode === 'later' ? this.depublishDate : this.today
			const objectsToProcess = [...this.selectedObjects]
			const successful = []
			const failed = []

			for (const obj of objectsToProcess) {
				try {
					// Skip publications whose schema lacks the publication date
					// fields the depublish flow writes to.
					if (!schemaHasPublicationDateFields(obj)) {
						continue
					}
					// Skip already-depublished items: their depublicatiedatum must not be overwritten.
					if (this.isDepublished(obj)) {
						continue
					}

					const clone = JSON.parse(JSON.stringify(obj))
					clone.depublicatiedatum = targetDate
					// publicatiedatum is left unchanged on purpose

					const register = clone['@self']?.register ?? clone.register
					const schema = clone['@self']?.schema ?? clone.schema

					await objectStore.saveObject(clone, { register, schema })
					successful.push(obj)
				} catch (err) {
					console.error('Error depublishing object:', err)
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
				this.error = t('opencatalogi', 'Failed to depublish {count} object(s)', { count: failed.length })
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
.depublish-step {
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
	min-width: 170px;
	width: auto;
	flex: 0 0 auto;
}

.mode-row__date {
	flex: 0 0 auto;
}
</style>

<style>
.mass-action-dialog {
	z-index: 10000 !important;
}
</style>
