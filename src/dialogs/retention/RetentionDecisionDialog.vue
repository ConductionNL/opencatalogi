<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog :name="title" size="normal" @update:open="onOpenChange">
		<div class="retention-decision">
			<div v-if="loading" class="retention-decision__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<template v-else-if="!done">
				<p>{{ intro }}</p>
				<NcTextField
					v-if="decision === 'extend'"
					v-model="months"
					type="number"
					min="1"
					:label="t('opencatalogi', 'Extra months')" />
				<NcTextArea
					v-model="note"
					:label="t('opencatalogi', 'Note')"
					:helperText="
						t(
							'opencatalogi',
							'Kept in the history of each publication and in the retention report.',
						)
					"
					required />
				<ul class="retention-decision__list">
					<li
						v-for="publication in publications"
						:key="publication.id"
						:data-id="publication.id">
						<span class="retention-decision__title">{{
							publication.title
						}}</span>
						<span
							v-if="decision === 'extend'"
							class="retention-decision__dates">
							{{ dateRange(publication) }}
						</span>
					</li>
				</ul>
			</template>
			<NcNoteCard v-if="done && failed.length === 0" type="success">
				{{ doneText }}
			</NcNoteCard>
			<NcNoteCard v-if="failed.length > 0" type="error">
				{{
					t('opencatalogi', 'Not saved for: {titles}', {
						titles: failed.map((item) => item.title).join(', '),
					})
				}}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton @click="close">
				{{ done ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done"
				variant="primary"
				:disabled="!canSubmit"
				@click="submit">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ title }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { navigationStore } from '../../store/store.js'

/**
 * RetentionDecisionDialog: extend the retention term of, depublish or archive
 * one or more publications, with the note the decision requires.
 *
 * Reads `navigationStore.dialogProperties` `{ decision, ids, publications }`.
 * Publications the caller did not hand over are read by id. Each publication
 * gets its own `POST /api/retention/publications/{id}/decision`, so each gets
 * its own history entry.
 *
 * @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001
 */
export default {
	name: 'RetentionDecisionDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			publications: [],
			loading: true,
			months: '12',
			note: '',
			saving: false,
			done: false,
			failed: [],
		}
	},

	computed: {
		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		properties() {
			return navigationStore.dialogProperties || {}
		},

		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		decision() {
			return ['extend', 'depublish', 'archive'].includes(
				this.properties.decision,
			)
				? this.properties.decision
				: 'extend'
		},

		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		title() {
			return {
				extend: t('opencatalogi', 'Extend term'),
				depublish: t('opencatalogi', 'Depublish now'),
				archive: t('opencatalogi', 'Archive'),
			}[this.decision]
		},

		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		intro() {
			const count = this.publications.length
			const vars = { count }
			if (this.decision === 'extend') {
				return count === 1
					? t(
							'opencatalogi',
							'Extend the retention term of 1 publication. The new end date counts from the current one.',
						)
					: t(
							'opencatalogi',
							'Extend the retention term of {count} publications. The new end date counts from the current one.',
							vars,
						)
			}
			if (this.decision === 'depublish') {
				return count === 1
					? t(
							'opencatalogi',
							'Take 1 publication off the public catalogue today.',
						)
					: t(
							'opencatalogi',
							'Take {count} publications off the public catalogue today.',
							vars,
						)
			}
			return count === 1
				? t(
						'opencatalogi',
						'Archive 1 publication. It leaves the public catalogue today.',
					)
				: t(
						'opencatalogi',
						'Archive {count} publications. They leave the public catalogue today.',
						vars,
					)
		},

		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		monthsValue() {
			const value = Number.parseInt(this.months, 10)
			return Number.isFinite(value) ? value : 0
		},

		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		canSubmit() {
			return (
				!this.saving
				&& this.publications.length > 0
				&& this.note.trim() !== ''
				&& (this.decision !== 'extend' || this.monthsValue > 0)
			)
		},

		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		doneText() {
			return {
				extend: t('opencatalogi', 'The retention term was extended.'),
				depublish: t('opencatalogi', 'Depublished.'),
				archive: t('opencatalogi', 'Archived.'),
			}[this.decision]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Take the handed-over publications and read the others by id.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001
		 */
		async load() {
			const given = Array.isArray(this.properties.publications)
				? this.properties.publications
				: []
			const known = new Set(given.map((item) => String(item.id)))
			const missing = (this.properties.ids || [])
				.map(String)
				.filter((id) => !known.has(id))
			const read = await Promise.all(
				missing.map((id) =>
					axios
						.get(
							generateUrl(
								'/apps/openregister/api/objects/{register}/{schema}/{id}',
								{
									register: loadState(
										'opencatalogi',
										'publication_register',
										'',
									),
									schema: loadState(
										'opencatalogi',
										'publication_schema',
										'',
									),
									id,
								},
							),
						)
						.then(({ data }) => data)
						.catch(() => null),
				),
			)
			this.publications = [...given, ...read.filter(Boolean)]
			this.loading = false
		},

		/**
		 * Post the decision for every publication, one by one.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001
		 */
		async submit() {
			this.saving = true
			const failed = []
			for (const publication of this.publications) {
				try {
					await axios.post(
						generateUrl(
							'/apps/opencatalogi/api/retention/publications/{id}/decision',
							{ id: publication.id },
						),
						{
							decision: this.decision,
							note: this.note.trim(),
							...(this.decision === 'extend'
								? { extendMonths: this.monthsValue }
								: {}),
						},
					)
				} catch {
					failed.push(publication)
				}
			}
			this.failed = failed
			this.saving = false
			this.done = true
		},

		/**
		 * The current and the new end date of an extension.
		 *
		 * @param {object} publication The publication.
		 * @return {string} "<current> to <new>", or '' without a current date.
		 * @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001
		 */
		dateRange(publication) {
			const current = new Date(publication?.retentionExpiresAt || '')
			if (Number.isNaN(current.getTime())) {
				return ''
			}
			const next = new Date(current)
			next.setMonth(next.getMonth() + this.monthsValue)
			const format = (date) =>
				date.toLocaleDateString(undefined, { dateStyle: 'medium' })
			return t('opencatalogi', '{from} to {to}', {
				from: format(current),
				to: format(next),
			})
		},

		/**
		 * @param {boolean} open Whether the dialog stays open.
		 * @return {void}
		 * @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001
		 */
		onOpenChange(open) {
			if (!open) {
				this.close()
			}
		},

		/** @spec openspec/specs/retention-review-queue/spec.md#requirement-a-retention-decision-is-taken-on-one-or-more-publications-with-a-note-rrq-001 */
		close() {
			navigationStore.setDialog(false)
		},
	},
}
</script>

<style scoped>
.retention-decision {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.retention-decision__loading {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
}

.retention-decision__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: 0;
	margin: 0;
	list-style: none;
}

.retention-decision__list li {
	display: flex;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-block: calc(var(--default-grid-baseline) * 2);
	border-block-end: 1px solid var(--color-border);
}

.retention-decision__title {
	font-weight: 600;
}

.retention-decision__dates {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
