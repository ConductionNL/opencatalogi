<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<MassObjectActionDialog
		:title="dialogTitle"
		:confirmLabel="confirmLabel"
		confirmVariant="primary"
		:confirmDisabled="submitDisabled"
		:loading="loading"
		:success="success"
		:error="error"
		:successMessage="successMessage"
		@close="closeDialog"
		@confirm="publishObjects()">
		<div class="publish-step">
			<div class="mode-row">
				<div class="mode-row__mode">
					<span
						v-for="opt in modeOptions"
						:key="opt.id"
						class="mode-row__mode-sizer"
						aria-hidden="true"
						>{{ opt.label }}</span
					>
					<NcSelect
						v-model="selectedMode"
						:options="modeOptions"
						:clearable="false"
						:searchable="false"
						labelAttribute="label"
						:aria-label-combobox="t('opencatalogi', 'Publishing mode')"
						:disabled="loading">
						<template #option="option">
							<span
								:title="option.title || ''"
								:class="{ 'mode-option-disabled': option.disabled }">
								{{ option.label }}
							</span>
						</template>
					</NcSelect>
				</div>
				<!--
					`@nextcloud/vue@9`'s NcDateTimePicker takes `modelValue` and emits
					ONLY `blur` / `update:modelValue` / `update:timezoneId`. The former
					`:value` prop and the `@input` / `@change` / `@update:value`
					listeners no longer exist, so the whole shotgun of handlers was
					dead. `:label` and `:disabled-date` are likewise not v9 props —
					the accessible name is `aria-label`, and the lower bound is
					enforced by `:min` (plus the active guard in `handleDateInput`).
				-->
				<NcDateTimePicker
					v-if="mode === 'later'"
					key="publish-later-date"
					class="mode-row__date"
					:modelValue="publishDateObj"
					:aria-label="t('opencatalogi', 'Publication date')"
					:placeholder="t('opencatalogi', 'Publication date')"
					type="date"
					:min="minPublishDate"
					:clearable="false"
					@update:modelValue="handleDateInput" />
			</div>

			<SelectedObjectsList
				hideTitle
				subtitleAttribute="summary"
				:showRemove="true"
				:isDisabled="isObjectUnsupported"
				:disabledReason="unsupportedReason" />
		</div>

		<template #notes>
			<NcNoteCard type="info">
				{{ infoText }}
			</NcNoteCard>
			<NcNoteCard
				v-if="alreadyPublishedCount > 0 && mode !== 'retroactive'"
				type="warning">
				{{ alreadyPublishedWarning }}
			</NcNoteCard>
			<NcNoteCard v-if="unsupportedCount > 0" type="warning">
				{{ unsupportedWarning }}
			</NcNoteCard>
			<NcNoteCard
				v-if="incompleteObjects.length > 0"
				type="warning"
				data-testid="publish-incomplete">
				<p>{{ incompleteWarning }}</p>
				<ul>
					<li v-for="entry in incompleteObjects" :key="entry.key">
						{{ entry.title }}: {{ entry.missing.join(', ') }}
					</li>
				</ul>
			</NcNoteCard>
		</template>

		<template #icon>
			<Publish :size="20" />
		</template>
	</MassObjectActionDialog>
</template>

<script>
import { NcDateTimePicker, NcNoteCard, NcSelect } from '@nextcloud/vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'
import MassObjectActionDialog from './MassObjectActionDialog.vue'
import {
	publishesThroughLifecycle,
	publishThroughLifecycle,
} from '../../services/publicationTransition.js'
import {
	publicationDateFields,
	publicationWindowStatus,
	publishPatch,
	todayDate,
} from '../../services/publicationWindow.js'
import {
	missingToPublish,
	refusalMessage,
} from '../../services/requiredToPublish.js'
import { objectStore } from '../../store/store.js'
import massObjectAction from './massObjectAction.js'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'MassPublishObjects',
	components: {
		MassObjectActionDialog,
		NcDateTimePicker,
		NcNoteCard,
		NcSelect,
		SelectedObjectsList,
		Publish,
	},

	mixins: [massObjectAction],

	data() {
		return {
			selectedMode: null,
			publishDate: null,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		mode() {
			return this.selectedMode?.id || 'now'
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		today() {
			return todayDate()
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
			return this.selectedObjects.some((obj) => this.isDepublished(obj))
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		allDepublished() {
			return (
				this.selectedObjects.length > 0
				&& this.selectedObjects.every((obj) => this.isDepublished(obj))
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		alreadyPublishedCount() {
			return this.selectedObjects.filter((obj) => this.isAlreadyPublished(obj))
				.length
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		alreadyPublishedWarning() {
			const count = this.alreadyPublishedCount
			if (count === 1) {
				return t(
					'opencatalogi',
					'1 of the selected publications is already published and will be skipped. Its publication date will not be changed.',
				)
			}
			return t(
				'opencatalogi',
				'{count} of the selected publications are already published and will be skipped. Their publication dates will not be changed.',
				{ count },
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		unsupportedCount() {
			return this.selectedObjects.filter((obj) => this.isObjectUnsupported(obj))
				.length
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		unsupportedWarning() {
			const count = this.unsupportedCount
			if (count === 1) {
				return t(
					'opencatalogi',
					'1 of the selected publications has a schema that does not support publishing and will be skipped. Ask your IT manager for help.',
				)
			}
			return t(
				'opencatalogi',
				'{count} of the selected publications have schemas that do not support publishing and will be skipped. Ask your IT manager for help.',
				{ count },
			)
		},

		/**
		 * The selected publications this publish would move but that miss a field
		 * the schema's lifecycle requires to publish, each with those fields.
		 *
		 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
		 */
		incompleteObjects() {
			if (this.mode === 'retroactive') return []
			return this.selectedObjects
				.filter(
					(obj) =>
						!this.isObjectUnsupported(obj)
						&& !this.isAlreadyPublished(obj),
				)
				.map((obj) => ({
					key: obj?.['@self']?.id ?? obj?.id ?? obj?.title,
					title: obj?.title || obj?.['@self']?.name || obj?.id,
					object: obj,
					missing: missingToPublish(obj),
				}))
				.filter((entry) => entry.missing.length > 0)
		},

		/**
		 * How many selected publications this publish actually moves.
		 *
		 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
		 */
		publishableCount() {
			return (
				this.selectedObjects.filter(
					(obj) =>
						!this.isObjectUnsupported(obj)
						&& (this.mode === 'retroactive'
							|| !this.isAlreadyPublished(obj)),
				).length - this.incompleteObjects.length
			)
		},

		/**
		 * The confirm button: "Publish", or "Publish 1 of 2 publications" when incomplete ones are left out.
		 *
		 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
		 */
		confirmLabel() {
			if (this.incompleteObjects.length === 0) {
				return t('opencatalogi', 'Publish')
			}
			return t('opencatalogi', 'Publish {count} of {total} publications', {
				count: this.publishableCount,
				total: this.selectedObjects.length,
			})
		},

		/**
		 * The note above the list of incomplete publications.
		 *
		 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
		 */
		incompleteWarning() {
			return t(
				'opencatalogi',
				'These publications miss a field required to publish and will be skipped:',
			)
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
						: t(
								'opencatalogi',
								'Only available when all selected items are depublished',
							),
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
				return t(
					'opencatalogi',
					'The publication date will be set to the chosen date. Any existing depublication date will be removed.',
				)
			}
			if (this.mode === 'retroactive') {
				return t(
					'opencatalogi',
					'The depublish date will be removed. The publish date will not change.',
				)
			}
			return t(
				'opencatalogi',
				"Publications will be published with today's date. Any existing depublication date will be removed.",
			)
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
			if (
				this.mode !== 'retroactive'
				&& this.alreadyPublishedCount === this.selectedObjects.length
			) {
				return true
			}
			if (this.publishableCount <= 0) {
				return true
			}
			return false
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		successMessage() {
			const plural = this.originalSelectedCount > 1
			if (this.mode === 'retroactive') {
				return plural
					? t(
							'opencatalogi',
							'Publications successfully published retroactive',
						)
					: t(
							'opencatalogi',
							'Publication successfully published retroactive',
						)
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
				if (
					this.mode === 'retroactive'
					&& (!this.anyDepublished || !this.allDepublished)
				) {
					this.selectedMode = this.modeOptions[0]
				}
			},
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
	mounted() {
		this.selectedMode = this.modeOptions[0]
	},

	methods: {
		/**
		 * Whether an object is published (live) today, its depublication date
		 * still ahead or not set. A scheduled one is not, so it can be moved to now.
		 *
		 * @param {object} obj - The publication object
		 * @return {boolean} true if currently published
		 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
		 */
		isAlreadyPublished(obj) {
			return publicationWindowStatus(obj, this.today) === 'published'
		},

		/**
		 * Whether an object is depublished: its depublication date has come.
		 * Drives the 'Publish retroactive' option.
		 *
		 * @param {object} obj - The publication object
		 * @return {boolean} true if currently depublished
		 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
		 */
		isDepublished(obj) {
			return publicationWindowStatus(obj, this.today) === 'depublished'
		},

		/**
		 * Predicate passed to SelectedObjectsList: items whose schema has no
		 * publication date (`publicationDate` or `publicatiedatum`) render at
		 * reduced opacity and are skipped during the publish loop.
		 *
		 * @param {object} obj - The publication object.
		 * @return {boolean} true when the object will be skipped.
		 * @spec openspec/specs/publication-selection-actions/spec.md#requirement-publish-and-depublish-work-on-any-schema-with-publication-date-fields-psa-004
		 */
		isObjectUnsupported(obj) {
			return !publicationDateFields(obj).publication
		},

		/**
		 * Tooltip text explaining why an unsupported item is greyed out.
		 *
		 * @return {string} The reason.
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		unsupportedReason() {
			return t(
				'opencatalogi',
				'This schema does not support publishing. Ask your IT manager for help.',
			)
		},

		/**
		 * Predicate for NcDateTimePicker's :disabled-date prop. Returns true for any
		 * date strictly before `minPublishDate` so the picker greys them out.
		 *
		 * @param {Date} date - A day passed by the picker
		 * @return {boolean} true if the date should be unselectable
		 */
		/**
		 * @param date
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1
		 */
		isDateBeforeMin(date) {
			if (!(date instanceof Date) || Number.isNaN(date.getTime())) return false
			const d = new Date(date)
			d.setHours(0, 0, 0, 0)
			return d.getTime() < this.minPublishDate.getTime()
		},

		/**
		 * @param value
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1
		 */
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

		/**
		 * Whether this publish leaves a selected object alone: its schema has no
		 * publication date fields, it is already published (outside retroactive
		 * mode, so its date is not overwritten), or it misses a field required to
		 * publish (REQ-PRP-003).
		 *
		 * @param {object} obj The selected object.
		 * @param {object[]} incomplete The selected objects missing a required field.
		 * @return {boolean} True when the object is skipped.
		 * @spec openspec/changes/archive/2026-11-25-publication-required-at-publish/specs/publications/spec.md#requirement-the-forms-say-what-is-needed-to-publish-req-prp-003
		 */
		skipsPublish(obj, incomplete) {
			if (this.isObjectUnsupported(obj)) return true
			if (this.mode !== 'retroactive' && this.isAlreadyPublished(obj)) {
				return true
			}
			return incomplete.includes(obj)
		},

		/**
		 * Publish one object. A publication that is not yet published moves
		 * through its lifecycle, so a refused move writes no date (REQ-PLC-003);
		 * anything else gets its dates saved.
		 *
		 * @param {object} obj The selected object.
		 * @param {string} targetDate The publication date to write.
		 * @return {Promise<void>} Rejects when the publish is refused.
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1
		 */
		async publishOne(obj, targetDate) {
			if (this.mode !== 'retroactive' && publishesThroughLifecycle(obj)) {
				await publishThroughLifecycle(obj, targetDate)
				return
			}

			// In retroactive mode the publication date is left unchanged on purpose.
			const patch = publishPatch(
				obj,
				this.mode === 'retroactive' ? null : targetDate,
			)
			const clone = Object.assign(JSON.parse(JSON.stringify(obj)), patch)

			const register = clone['@self']?.register ?? clone.register
			const schema = clone['@self']?.schema ?? clone.schema
			await objectStore.saveObject(clone, { register, schema })
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-1 */
		async publishObjects() {
			if (this.selectedMode?.disabled) return
			this.loading = true
			this.error = false

			const targetDate = this.mode === 'later' ? this.publishDate : this.today
			const successful = []
			const failed = []
			const refusals = []
			const incomplete = this.incompleteObjects.map((entry) => entry.object)

			for (const obj of [...this.selectedObjects]) {
				if (this.skipsPublish(obj, incomplete)) continue
				try {
					await this.publishOne(obj, targetDate)
					successful.push(obj)
				} catch (err) {
					console.error('Error publishing object:', err)
					failed.push({ object: obj, error: err })
					// OpenRegister's refusal names the publication's missing fields (REQ-PRP-003).
					if (err?.response?.status === 422) {
						refusals.push(
							`${obj?.title || obj?.id}: ${refusalMessage(err)}`,
						)
					}
				}
			}

			this.finishMassAction({ successful, failed }, (count) =>
				t('opencatalogi', 'Failed to publish {count} object(s)', { count }),
			)
			if (refusals.length > 0) {
				this.error = refusals.join('\n')
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
