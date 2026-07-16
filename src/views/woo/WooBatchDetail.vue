<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - WOO disclosure-batch detail page (woo-transparency).
  -
  - Surfaces the OpenRegister deck-board widget (the queue/board — NOT a bespoke
  - table; ADR-022) plus the WOO-specific surfaces OpenCatalogi owns: the
  - per-status progress summary, the redaction review (WooRedactionView), the
  - inventarislijst download, and the ready-for-review / publish actions (the
  - publish transition is gated by the OpenRegister approval-workflow chain).
  -->
<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { canMarkReadyForReview } from '../../services/wooHelpers.js'
import WooRedactionView from './WooRedactionView.vue'

export default {
	name: 'WooBatchDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		WooRedactionView,
	},
	data() {
		return {
			t,
			loading: false,
			error: null,
			batch: null,
			activeDocument: null,
		}
	},
	computed: {
		/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-frontend-components */
		batchId() {
			return this.$route?.params?.id || ''
		},
		/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-document-queue-consumes-the-openregister-deck-leaf */
		deckUnavailable() {
			return this.batch && this.batch.deckAvailable === false
		},
		/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-frontend-components */
		summary() {
			return this.batch?.documentSummary || { counts: {}, total: 0, assessed: 0, progressLabel: '0/0' }
		},
		/** @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model */
		canReview() {
			return this.batch?.status === 'in_progress' && canMarkReadyForReview(this.summary)
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
					this.loadBatch()
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
				const { data } = await axios.get(generateUrl(`/apps/opencatalogi/api/woo/batches/${this.batchId}`))
				this.batch = data
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.loading = false
			}
		},
		/**
		 * Mark the batch ready for review (opens the approval gate).
		 *
		 * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
		 * @return {Promise<void>}
		 */
		async markReadyForReview() {
			try {
				await axios.post(generateUrl(`/apps/opencatalogi/api/woo/batches/${this.batchId}/ready-for-review`))
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
				const { data } = await axios.post(generateUrl(`/apps/opencatalogi/api/woo/batches/${this.batchId}/publish`))
				this.batch = data
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			}
		},
		/**
		 * Build the inventarislijst download URL (CSV by default).
		 *
		 * @param {string} format The export format (csv|html).
		 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
		 * @return {string} The download URL.
		 */
		inventarislijstUrl(format) {
			return generateUrl(`/apps/opencatalogi/api/woo/batches/${this.batchId}/inventarislijst?format=${format}`)
		},
	},
}
</script>

<template>
	<div class="woo-batch">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent v-else-if="!batch" :name="t('opencatalogi', 'Batch not found')" />
		<div v-else>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="deckUnavailable" type="warning">
				{{ t('opencatalogi', 'Deck integration required for the WOO queue') }}
			</NcNoteCard>

			<h2>{{ t('opencatalogi', 'WOO batch') }} — {{ batch.caseReference }}</h2>
			<p class="woo-batch__progress">
				{{ t('opencatalogi', 'Assessed: {progress}', { progress: summary.progressLabel }) }}
			</p>

			<ul class="woo-batch__counts">
				<li>{{ t('opencatalogi', 'Te beoordelen') }}: {{ summary.counts.te_beoordelen || 0 }}</li>
				<li>{{ t('opencatalogi', 'Openbaar') }}: {{ summary.counts.openbaar || 0 }}</li>
				<li>{{ t('opencatalogi', 'Deels openbaar') }}: {{ summary.counts.deels_openbaar || 0 }}</li>
				<li>{{ t('opencatalogi', 'Niet openbaar') }}: {{ summary.counts.niet_openbaar || 0 }}</li>
			</ul>

			<!-- The queue/board is the OpenRegister deck widget, surfaced via the
			     app manifest on this object detail page (ADR-019 / ADR-024). -->
			<section class="woo-batch__deck">
				<h3>{{ t('opencatalogi', 'Document queue (Deck board)') }}</h3>
				<p class="woo-batch__hint">
					{{ t('opencatalogi', 'The document queue is rendered by the Deck board widget on this page.') }}
				</p>
			</section>

			<WooRedactionView
				v-if="activeDocument"
				:document-id="activeDocument.id"
				:batch-id="batchId"
				:entities="activeDocument.entities || []" />

			<div class="woo-batch__actions">
				<NcButton :href="inventarislijstUrl('csv')">
					{{ t('opencatalogi', 'Download inventarislijst (CSV)') }}
				</NcButton>
				<NcButton :href="inventarislijstUrl('html')">
					{{ t('opencatalogi', 'Download inventarislijst (PDF/A)') }}
				</NcButton>
				<NcButton v-if="canReview" type="secondary" @click="markReadyForReview">
					{{ t('opencatalogi', 'Mark ready for review') }}
				</NcButton>
				<NcButton v-if="canPublish" type="primary" @click="publish">
					{{ t('opencatalogi', 'Publish to reading room') }}
				</NcButton>
			</div>

			<p v-if="batch.wooPublication && batch.wooPublication.readingRoomUrl" class="woo-batch__published">
				<a :href="batch.wooPublication.readingRoomUrl" target="_blank" rel="noopener noreferrer">
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

.woo-batch__deck {
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 16px;
}

.woo-batch__hint {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.woo-batch__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 16px;
}

.woo-batch__published {
	margin-top: 12px;
}
</style>
