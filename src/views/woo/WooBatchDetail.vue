<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - WOO disclosure-batch detail page (woo-transparency).
  -
  - Shows the document queue in four columns (woo-document-assessment-screen):
  - the batch's assessments are read through OpenRegister's object store, and
  - a move saves through PUT /api/woo/batches/{batchId}/documents/{docId}, by
  - drag or by the card's action menu. Also the per-status progress summary,
  - the inventarislijst download, and the ready-for-review / publish actions
  - (the publish transition is gated by the OpenRegister approval-workflow chain).
  -->
<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActionLink,
	NcActions,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import WooAssessmentDialog from '../../dialogs/woo/WooAssessmentDialog.vue'
import {
	ASSESSMENTS,
	canMarkReadyForReview,
	formatSize,
	groupByAssessment,
	needsGrounds,
	redactionState,
	replaceAssessment,
} from '../../services/wooHelpers.js'
import { objectStore } from '../../store/store.js'

/**
 * The object store type the queue reads.
 */
const ASSESSMENT_TYPE = 'wooAssessment'

/**
 * An initial-state value, or an empty string when it is not there.
 *
 * @param {string} key The key.
 * @return {string} The value.
 */
function stateValue(key) {
	try {
		return String(loadState('opencatalogi', key, '') || '')
	} catch {
		return ''
	}
}

export default {
	name: 'WooBatchDetail',
	components: {
		NcActionButton,
		NcActionLink,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		WooAssessmentDialog,
	},

	data() {
		return {
			t,
			formatSize,
			loading: false,
			error: null,
			batch: null,
			assessments: [],
			queueError: null,
			cardErrors: {},
			saving: {},
			dialog: null,
			dragged: null,
			announcement: '',
		}
	},

	computed: {
		/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-frontend-components */
		batchId() {
			return this.$route?.params?.id || ''
		},

		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002 */
		columns() {
			const groups = groupByAssessment(this.assessments)
			const labels = {
				te_beoordelen: t('opencatalogi', 'To assess'),
				openbaar: t('opencatalogi', 'Public'),
				deels_openbaar: t('opencatalogi', 'Partly public'),
				niet_openbaar: t('opencatalogi', 'Not public'),
			}
			return ASSESSMENTS.map((key) => ({
				key,
				label: labels[key],
				items: groups[key],
			}))
		},

		/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-frontend-components */
		summary() {
			return (
				this.batch?.documentSummary || {
					counts: {},
					total: 0,
					assessed: 0,
					progressLabel: '0/0',
				}
			)
		},

		/** @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-the-officer-sees-why-a-partly-public-document-cannot-be-published-req-wrp-002 */
		unredacted() {
			return this.summary.unredacted || []
		},

		/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model */
		canReview() {
			return (
				this.batch?.status === 'in_progress'
				&& canMarkReadyForReview(this.summary)
			)
		},

		/** @spec openspec/specs/woo-transparency/spec.md#requirement-reading-room-publication */
		canPublish() {
			return this.batch?.status === 'ready_for_review'
		},
	},

	watch: {
		batchId: {
			immediate: true,
			/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-frontend-components */
			handler() {
				if (this.batchId) {
					this.loadBatch().then(() => this.loadAssessments())
				}
			},
		},
	},

	methods: {
		/**
		 * Load the batch + derived document summary.
		 *
		 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
		 * @return {Promise<void>}
		 */
		async loadBatch() {
			this.loading = true
			this.error = null
			try {
				this.batch = await this.requestBatch()
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch this batch with its derived document summary.
		 *
		 * @return {Promise<object>} The batch.
		 */
		async requestBatch() {
			const { data } = await axios.get(
				generateUrl(`/apps/opencatalogi/api/woo/batches/${this.batchId}`),
			)
			return data
		},

		/**
		 * Load the batch's assessments through OpenRegister's object store,
		 * filtered on its case reference.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {Promise<void>}
		 */
		async loadAssessments() {
			this.queueError = null
			const register = stateValue('woo_register')
			const schema = stateValue('woo_assessment_schema')
			if (!this.batch?.caseReference || !register || !schema) {
				this.assessments = []
				return
			}
			try {
				await objectStore.registerObjectType(
					ASSESSMENT_TYPE,
					schema,
					register,
				)
				await objectStore.fetchCollection(ASSESSMENT_TYPE, {
					caseReference: this.batch.caseReference,
				})
				const documents = this.batch.documents || []
				this.assessments = (
					objectStore.getCollection(ASSESSMENT_TYPE).results || []
				).filter(
					(item) => documents.length === 0 || documents.includes(item.id),
				)
			} catch (err) {
				this.queueError = err.message
			}
		},

		/**
		 * Move a card to another column: ask the grounds first where the
		 * column needs them, else save at once.
		 *
		 * @param {object} item The assessment.
		 * @param {string} target The target assessment.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {Promise<void>|void}
		 */
		requestMove(item, target) {
			if (!item || item.assessment === target) {
				return
			}
			if (needsGrounds(target)) {
				this.dialog = { item, target }
				return
			}
			return this.saveMove(item, target, [])
		},

		/**
		 * The grounds were chosen: save the move the dialog was opened for.
		 *
		 * @param {Array<string>} grounds The ground codes.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {Promise<void>}
		 */
		async confirmGrounds(grounds) {
			const { item, target } = this.dialog
			this.dialog = null
			await this.saveMove(item, target, grounds)
		},

		/**
		 * Save an assessment. On a refusal the card stays where it was and shows why.
		 *
		 * @param {object} item The assessment.
		 * @param {string} target The new assessment.
		 * @param {Array<string>} grounds The refusal grounds.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {Promise<void>}
		 */
		async saveMove(item, target, grounds) {
			this.saving = { ...this.saving, [item.id]: true }
			this.cardErrors = { ...this.cardErrors, [item.id]: null }
			try {
				const { data } = await axios.put(
					generateUrl(
						`/apps/opencatalogi/api/woo/batches/${this.batchId}/documents/${item.id}`,
					),
					{ assessment: target, weigeringsgronden: grounds },
				)
				this.assessments = replaceAssessment(this.assessments, {
					...data,
					id: item.id,
				})
				const column = this.columns.find((c) => c.key === target)
				this.announcement = t('opencatalogi', '{name} moved to {column}', {
					name: item.fileName || item.documentReference || '',
					column: column?.label || target,
				})
				this.batch = await this.requestBatch()
			} catch (err) {
				const message = err.response?.data?.error || err.message
				this.cardErrors = { ...this.cardErrors, [item.id]: message }
				this.announcement = message
			} finally {
				this.saving = { ...this.saving, [item.id]: false }
			}
		},

		/**
		 * Remember the dragged card.
		 *
		 * @param {object} item The assessment.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {void}
		 */
		onDragStart(item) {
			this.dragged = item
		},

		/**
		 * A card was dropped on a column.
		 *
		 * @param {string} target The column's assessment.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {Promise<void>|void}
		 */
		onDrop(target) {
			const item = this.dragged
			this.dragged = null
			return this.requestMove(item, target)
		},

		/**
		 * The redaction state of a partly public card, in words.
		 *
		 * @param {object} item The assessment.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {string} The label, or an empty string.
		 */
		redactionLabel(item) {
			switch (redactionState(item)) {
				case 'failed':
					return (
						item.redactionMessage
						|| t('opencatalogi', 'Redaction failed')
					)
				case 'pending':
					return t('opencatalogi', 'Redacting')
				case 'unchecked':
					return t('opencatalogi', 'Redacted, not verified')
				case 'checked':
					return t('opencatalogi', 'Verified')
				default:
					return ''
			}
		},

		/**
		 * The original file of a card in Nextcloud Files, when its reference is a file id.
		 *
		 * @param {object} item The assessment.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {string} The URL, or an empty string.
		 */
		previewUrl(item) {
			const reference = String(item?.documentReference || '')
			return /^\d+$/.test(reference) ? generateUrl(`/f/${reference}`) : ''
		},

		/**
		 * Mark the batch ready for review (opens the approval gate).
		 *
		 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
		 * @return {Promise<void>}
		 */
		async markReadyForReview() {
			try {
				await axios.post(
					generateUrl(
						`/apps/opencatalogi/api/woo/batches/${this.batchId}/ready-for-review`,
					),
				)
				await this.loadBatch()
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			}
		},

		/**
		 * Publish the batch to the public reading room.
		 *
		 * @spec openspec/specs/woo-transparency/spec.md#requirement-reading-room-publication
		 * @return {Promise<void>}
		 */
		async publish() {
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/opencatalogi/api/woo/batches/${this.batchId}/publish`,
					),
				)
				this.batch = data
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			}
		},

		/**
		 * Build the inventarislijst download URL (CSV by default).
		 *
		 * @param {string} format The export format (csv|pdf).
		 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
		 * @return {string} The download URL.
		 */
		inventarislijstUrl(format) {
			return generateUrl(
				`/apps/opencatalogi/api/woo/batches/${this.batchId}/inventarislijst?format=${format}`,
			)
		},
	},
}
</script>

<template>
	<div class="woo-batch">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent
			v-else-if="!batch"
			:name="t('opencatalogi', 'Batch not found')" />
		<div v-else>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="unredacted.length > 0" type="warning">
				<p>
					{{
						t(
							'opencatalogi',
							'These partly public documents cannot be published until they have a verified redacted version.',
						)
					}}
				</p>
				<ul class="woo-batch__unredacted">
					<li v-for="item in unredacted" :key="item.fileName">
						{{ item.fileName }}: {{ item.reason }}
					</li>
				</ul>
			</NcNoteCard>

			<h2>{{ t('opencatalogi', 'WOO batch') }} — {{ batch.caseReference }}</h2>
			<p class="woo-batch__progress">
				{{
					t('opencatalogi', 'Assessed: {progress}', {
						progress: summary.progressLabel,
					})
				}}
			</p>

			<section
				class="woo-batch__queue"
				:aria-label="t('opencatalogi', 'Document queue')">
				<h3>{{ t('opencatalogi', 'Document queue') }}</h3>
				<NcNoteCard v-if="queueError" type="error">
					{{ queueError }}
				</NcNoteCard>
				<p class="hidden-visually" aria-live="polite">
					{{ announcement }}
				</p>
				<div class="woo-batch__columns">
					<div
						v-for="column in columns"
						:key="column.key"
						class="woo-batch__column"
						:data-column="column.key"
						@dragover.prevent
						@drop.prevent="onDrop(column.key)">
						<h4 class="woo-batch__column-title">
							{{ column.label }}
							<span class="woo-batch__count">{{
								summary.counts[column.key] || 0
							}}</span>
						</h4>
						<ul class="woo-batch__cards">
							<li
								v-for="item in column.items"
								:key="item.id"
								class="woo-batch__card"
								:data-assessment="item.id"
								draggable="true"
								@dragstart="onDragStart(item)">
								<div class="woo-batch__card-head">
									<span class="woo-batch__card-title">{{
										item.fileName || item.documentReference
									}}</span>
									<NcActions
										:aria-label="
											t('opencatalogi', 'Actions for {name}', {
												name:
													item.fileName
													|| item.documentReference
													|| '',
											})
										">
										<NcActionButton
											v-for="other in columns.filter(
												(c) => c.key !== column.key,
											)"
											:key="other.key"
											:disabled="saving[item.id] === true"
											@click="requestMove(item, other.key)">
											{{
												t(
													'opencatalogi',
													'Move to {column}',
													{ column: other.label },
												)
											}}
										</NcActionButton>
										<NcActionLink
											v-if="previewUrl(item)"
											:href="previewUrl(item)"
											target="_blank">
											{{ t('opencatalogi', 'Preview') }}
										</NcActionLink>
									</NcActions>
								</div>
								<p class="woo-batch__card-meta">
									{{
										[item.fileType, formatSize(item.fileSize)]
											.filter(Boolean)
											.join(' · ')
									}}
								</p>
								<p
									v-if="(item.weigeringsgronden || []).length > 0"
									class="woo-batch__chips">
									<span
										v-for="ground in item.weigeringsgronden"
										:key="ground"
										class="woo-batch__chip"
										>{{ ground }}</span
									>
								</p>
								<p
									v-if="redactionLabel(item)"
									class="woo-batch__redaction">
									{{ redactionLabel(item) }}
								</p>
								<p
									v-if="cardErrors[item.id]"
									class="woo-batch__card-error"
									role="alert">
									{{ cardErrors[item.id] }}
								</p>
							</li>
						</ul>
					</div>
				</div>
			</section>

			<WooAssessmentDialog
				v-if="dialog"
				:target="dialog.target"
				:documentName="dialog.item.fileName || ''"
				:current="dialog.item.weigeringsgronden || []"
				@confirm="confirmGrounds"
				@close="dialog = null" />

			<div class="woo-batch__actions">
				<NcButton :href="inventarislijstUrl('csv')">
					{{ t('opencatalogi', 'Download inventarislijst (CSV)') }}
				</NcButton>
				<NcButton :href="inventarislijstUrl('pdf')">
					{{ t('opencatalogi', 'Download inventarislijst (PDF/A)') }}
				</NcButton>
				<NcButton
					v-if="canReview"
					variant="secondary"
					@click="markReadyForReview">
					{{ t('opencatalogi', 'Mark ready for review') }}
				</NcButton>
				<NcButton v-if="canPublish" variant="primary" @click="publish">
					{{ t('opencatalogi', 'Publish to reading room') }}
				</NcButton>
			</div>

			<p
				v-if="batch.wooPublication && batch.wooPublication.readingRoomUrl"
				class="woo-batch__published">
				<a
					:href="batch.wooPublication.readingRoomUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('opencatalogi', 'Public reading room') }}
				</a>
			</p>
		</div>
	</div>
</template>

<style scoped>
.woo-batch {
	padding: 16px;
}

.woo-batch__progress {
	font-weight: bold;
}

.woo-batch__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	list-style: none;
	padding: 0;
	margin: 8px 0 16px;
}

.woo-batch__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	margin-bottom: 16px;
}

.woo-batch__column {
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 8px;
	min-height: 120px;
}

.woo-batch__column-title {
	display: flex;
	justify-content: space-between;
	margin: 0 0 8px;
}

.woo-batch__count {
	color: var(--color-text-maxcontrast);
}

.woo-batch__cards {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.woo-batch__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	cursor: grab;
}

.woo-batch__card-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 4px;
}

.woo-batch__card-title {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.woo-batch__card-meta,
.woo-batch__redaction {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.woo-batch__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 4px 0 0;
}

.woo-batch__chip {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	border-radius: var(--border-radius-pill);
	padding: 0 8px;
}

.woo-batch__card-error {
	color: var(--color-text-error, var(--color-error-text));
	margin: 4px 0 0;
}

.woo-batch__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 16px;
}

.woo-batch__unredacted {
	margin: 4px 0 0;
	padding-inline-start: 20px;
}

.woo-batch__published {
	margin-top: 12px;
}
</style>
