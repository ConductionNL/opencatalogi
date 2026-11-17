<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - The review of a Woo batch (woo-review-surface, REQ-WRV-001, REQ-WRV-002,
  - REQ-WRV-003). In place of the assessment queue while the batch is in
  - review: each document as the public will see it, the file that will be
  - published and its public metadata, with the redaction log of a partly
  - public document. Approve stays disabled until the reviewer opened every
  - public view; Reject and Send back ask the reason in their own dialog.
  -->
<template>
	<section class="woo-review" :aria-label="t('opencatalogi', 'Review')">
		<h3>{{ t('opencatalogi', 'Review: what the public will see') }}</h3>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<ul v-if="!loading" class="woo-review__documents">
			<li
				v-for="doc in documents"
				:key="doc.documentId"
				class="woo-review__document"
				:data-document="doc.documentId">
				<div class="woo-review__head">
					<span class="woo-review__title">{{
						doc.title || doc.documentId
					}}</span>
					<span class="woo-review__assessment">{{
						assessmentLabel(doc.assessment)
					}}</span>
				</div>
				<p
					v-if="doc.assessment === 'niet_openbaar'"
					class="woo-review__muted">
					{{
						t(
							'opencatalogi',
							'Not published. The public sees only its line in the inventory list.',
						)
					}}
				</p>
				<template v-else>
					<p>
						<a
							v-if="doc.viewerUrl"
							:href="doc.viewerUrl"
							target="_blank"
							rel="noopener noreferrer"
							@click="markSeen(doc)">
							{{ t('opencatalogi', 'Open the public view') }}
						</a>
						<span v-else class="woo-review__missing">
							{{
								t(
									'opencatalogi',
									'No file will be published for this document yet.',
								)
							}}
						</span>
						<span v-if="isSeen(doc)" class="woo-review__seen">
							{{ t('opencatalogi', 'Opened') }}
						</span>
					</p>
					<dl v-if="doc.publicMetadata" class="woo-review__metadata">
						<template
							v-for="field in metadataFields(doc.publicMetadata)"
							:key="field.key">
							<dt>
								{{ field.label }}
							</dt>
							<dd>
								{{ field.value }}
							</dd>
						</template>
					</dl>
					<RedactionLog
						v-if="doc.assessment === 'deels_openbaar'"
						:log="doc.redactionLog || []" />
				</template>
				<p v-if="doc.sentBackReason" class="woo-review__muted">
					{{
						t('opencatalogi', 'Sent back: {reason}', {
							reason: doc.sentBackReason.reason,
						})
					}}
				</p>
				<NcButton
					variant="tertiary"
					@click="dialog = { scope: 'document', doc }">
					{{ t('opencatalogi', 'Send back') }}
				</NcButton>
			</li>
		</ul>
		<NcNoteCard v-if="!loading && unseen.length > 0" type="info">
			{{
				t(
					'opencatalogi',
					'Open the public view of every document before you approve. Not opened yet: {names}',
					{
						names: unseen
							.map((doc) => doc.title || doc.documentId)
							.join(', '),
					},
				)
			}}
		</NcNoteCard>
		<div class="woo-review__actions">
			<NcButton
				variant="primary"
				:disabled="loading || unseen.length > 0 || busy"
				@click="approve">
				{{ t('opencatalogi', 'Approve') }}
			</NcButton>
			<NcButton :disabled="busy" @click="dialog = { scope: 'batch' }">
				{{ t('opencatalogi', 'Reject') }}
			</NcButton>
		</div>
		<RejectReasonDialog
			v-if="dialog"
			:scope="dialog.scope"
			:subject="dialog.doc ? dialog.doc.title : caseReference"
			@confirm="onReason"
			@close="dialog = null" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import RejectReasonDialog from '../../dialogs/review/RejectReasonDialog.vue'
import RedactionLog from './RedactionLog.vue'

export default {
	name: 'BatchReviewPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		RedactionLog,
		RejectReasonDialog,
	},

	props: {
		/** The batch uuid. */
		batchId: {
			type: String,
			required: true,
		},

		/** The batch's case reference, named in the reject dialog. */
		caseReference: {
			type: String,
			default: '',
		},
	},

	emits: ['changed'],

	data() {
		return {
			t,
			loading: false,
			busy: false,
			error: null,
			documents: [],
			unseen: [],
			dialog: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load every document's public view.
		 *
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const { data } = await axios.get(this.url('public-preview'))
				this.documents = data?.documents || []
				this.unseen = data?.unseen || []
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * A route of this batch's review.
		 *
		 * @param {string} path The path below the batch.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
		 * @return {string} The URL.
		 */
		url(path) {
			return generateUrl(
				`/apps/opencatalogi/api/woo/batches/${this.batchId}/${path}`,
			)
		},

		/**
		 * Whether the reviewer opened a document's public view.
		 *
		 * @param {object} doc The document.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
		 * @return {boolean} True when opened.
		 */
		isSeen(doc) {
			return !this.unseen.some((item) => item.documentId === doc.documentId)
		},

		/**
		 * Record that the reviewer opened a public view.
		 *
		 * @param {object} doc The document.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
		 * @return {Promise<void>}
		 */
		async markSeen(doc) {
			try {
				const { data } = await axios.post(this.url('preview-seen'), {
					documentId: doc.documentId,
				})
				this.unseen = data?.unseen || []
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			}
		},

		/**
		 * The assessment as a label.
		 *
		 * @param {string} assessment The assessment.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
		 * @return {string} The label.
		 */
		assessmentLabel(assessment) {
			const labels = {
				te_beoordelen: t('opencatalogi', 'To assess'),
				openbaar: t('opencatalogi', 'Public'),
				deels_openbaar: t('opencatalogi', 'Partly public'),
				niet_openbaar: t('opencatalogi', 'Not public'),
			}
			return labels[assessment] || assessment
		},

		/**
		 * The public metadata as label and value pairs.
		 *
		 * @param {object} metadata The public metadata.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
		 * @return {Array<{key: string, label: string, value: string}>} The fields.
		 */
		metadataFields(metadata) {
			const labels = {
				documentTitle: t('opencatalogi', 'Document'),
				title: t('opencatalogi', 'Publication title'),
				wooCategory: t('opencatalogi', 'Information category'),
				publicationKind: t('opencatalogi', 'Kind of publication'),
				publicationDate: t('opencatalogi', 'Publication date'),
				caseReference: t('opencatalogi', 'Case reference'),
			}
			return Object.keys(labels)
				.filter((key) => metadata[key] !== undefined && metadata[key] !== '')
				.map((key) => ({
					key,
					label: labels[key],
					value: String(metadata[key]),
				}))
		},

		/**
		 * Approve the batch.
		 *
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
		 * @return {Promise<void>}
		 */
		async approve() {
			await this.move(() => axios.post(this.url('approve')))
		},

		/**
		 * The reviewer gave the reason: reject the batch, or send one document back.
		 *
		 * @param {string} reason The reason.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
		 * @return {Promise<void>}
		 */
		async onReason(reason) {
			const dialog = this.dialog
			this.dialog = null
			if (dialog?.scope === 'document') {
				await this.move(() =>
					axios.post(
						generateUrl(
							`/apps/opencatalogi/api/woo/assessments/${dialog.doc.documentId}/send-back`,
						),
						{ batchId: this.batchId, reason },
					),
				)
				await this.load()
				return
			}
			await this.move(() => axios.post(this.url('reject'), { reason }))
		},

		/**
		 * Run one review move and tell the page, or show why it was refused.
		 *
		 * @param {function(): Promise<object>} request The request.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
		 * @return {Promise<void>}
		 */
		async move(request) {
			this.busy = true
			this.error = null
			try {
				await request()
				this.$emit('changed')
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.woo-review__documents {
	list-style: none;
	margin: 0 0 16px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.woo-review__document {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.woo-review__head {
	display: flex;
	justify-content: space-between;
	gap: 8px;
}

.woo-review__title {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.woo-review__assessment,
.woo-review__muted,
.woo-review__seen {
	color: var(--color-text-maxcontrast);
}

.woo-review__seen {
	margin-inline-start: 8px;
}

.woo-review__missing {
	color: var(--color-text-error, var(--color-error-text));
}

.woo-review__metadata {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 12px;
	margin: 8px 0;
}

.woo-review__metadata dt {
	font-weight: bold;
}

.woo-review__metadata dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.woo-review__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
