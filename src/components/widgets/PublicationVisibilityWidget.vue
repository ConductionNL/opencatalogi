<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publication-visibility" data-testid="publication-visibility">
		<p
			class="publication-visibility__state"
			data-testid="publication-visibility-state">
			{{ stateLabel }}
		</p>

		<div class="publication-visibility__actions">
			<NcButton
				v-if="state === 'draft' || state === 'scheduled'"
				variant="primary"
				:disabled="busy"
				data-testid="publication-publish-now"
				@click="publish">
				{{ t('opencatalogi', 'Publish now') }}
			</NcButton>
			<NcButton
				v-if="state === 'withdrawn'"
				variant="primary"
				:disabled="busy"
				data-testid="publication-publish-again"
				@click="publish">
				{{ t('opencatalogi', 'Publish again') }}
			</NcButton>
			<NcButton
				v-if="state === 'public' || state === 'scheduled'"
				variant="error"
				:disabled="busy"
				data-testid="publication-withdraw"
				@click="openWithdraw">
				{{ t('opencatalogi', 'Withdraw') }}
			</NcButton>
		</div>

		<section
			v-if="withdrawn.length > 0"
			class="publication-visibility__withdrawn"
			data-testid="publication-withdrawn-documents">
			<h4 class="publication-visibility__withdrawn-title">
				{{ t('opencatalogi', 'Withdrawn documents') }}
			</h4>
			<ul class="publication-visibility__withdrawn-list">
				<li
					v-for="document in withdrawn"
					:key="document.id"
					class="publication-visibility__withdrawn-item"
					data-testid="publication-withdrawn-document">
					<span class="publication-visibility__withdrawn-text">
						<strong>{{ document.label }}</strong>
						<span data-testid="publication-withdrawn-date">
							{{ t('opencatalogi', 'Withdrawn on {date}', { date: formatDate(document.withdrawnAt) }) }}
						</span>
						<span v-if="document.reason" data-testid="publication-withdrawn-reason">
							{{ t('opencatalogi', 'Reason: {reason}', { reason: document.reason }) }}
						</span>
					</span>
					<NcButton
						:disabled="busy"
						data-testid="publication-republish-document"
						@click="openRepublish(document)">
						{{ t('opencatalogi', 'Republish') }}
					</NcButton>
				</li>
			</ul>
		</section>

		<NcNoteCard
			v-if="message"
			:type="messageType"
			data-testid="publication-visibility-message">
			{{ message }}
		</NcNoteCard>

		<WithdrawPublicationDialog
			:open="dialogOpen"
			:busy="busy"
			:documents="state === 'public' ? withdrawable : []"
			@close="dialogOpen = false"
			@confirm="withdraw" />

		<RepublishDocumentDialog
			:open="republishing !== null"
			:busy="busy"
			:document="republishing"
			@close="republishing = null"
			@confirm="republish" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import RepublishDocumentDialog from '../../dialogs/publication/RepublishDocumentDialog.vue'
import WithdrawPublicationDialog from '../../dialogs/publication/WithdrawPublicationDialog.vue'
import {
	documentOptions,
	filesRouteParams,
	withdrawnDocuments,
} from '../../services/publicationVisibility.js'
import { injectedObjectContext } from './objectContext.js'

/**
 * The withdraw endpoint's path: one document's, or the whole publication's.
 *
 * @param {{fileId: string}} answer What the dialog collected.
 * @return {string} The path after the publication id.
 */
function withdrawPath(answer) {
	return answer.fileId
		? 'files/' + encodeURIComponent(answer.fileId) + '/withdraw'
		: 'withdraw'
}

/**
 * Publish now, withdraw with a reason, and publish again (REQ-PPW-002/003/004).
 *
 * The state comes from the server, which reads the same dates OpenRegister's
 * published predicate reads, so the page never works it out from dates in the
 * browser. Each button shows only when its move applies.
 */
export default {
	name: 'PublicationVisibilityWidget',
	components: { NcButton, NcNoteCard, RepublishDocumentDialog, WithdrawPublicationDialog },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},

	props: {
		register: { type: String, default: '' },
		schema: { type: [String, Object], default: '' },
		objectId: { type: String, default: '' },
		content: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			state: '',
			documents: [],
			withdrawn: [],
			republishing: null,
			busy: false,
			dialogOpen: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/** @spec exclude presentational widget adapter; resolves the object context from inject. */
		ctx() {
			return injectedObjectContext(this)
		},

		/** @spec exclude presentational widget adapter; derives the object id. */
		resolvedObjectId() {
			return this.objectId || this.ctx.objectId || this.content.objectId || ''
		},

		/**
		 * The documents a withdrawal can pick: the ones not withdrawn already.
		 *
		 * @return {Array<{id: (string|number), label: string}>} The documents.
		 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
		 */
		withdrawable() {
			const withdrawnIds = new Set(this.withdrawn.map((document) => String(document.id)))
			return this.documents.filter((document) => !withdrawnIds.has(String(document.id)))
		},

		/** @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001 */
		stateLabel() {
			const labels = {
				draft: this.t('opencatalogi', 'Not published yet.'),
				scheduled: this.t(
					'opencatalogi',
					'Scheduled: it becomes public on its publication date.',
				),

				public: this.t('opencatalogi', 'Public.'),
				withdrawn: this.t(
					'opencatalogi',
					'Withdrawn: readers of the public site no longer see it.',
				),

				archived: this.t(
					'opencatalogi',
					'Archived: kept for retention, never shown publicly again.',
				),
			}
			return labels[this.state] || ''
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/**
			 * Load the state once the object id is known.
			 *
			 * @param {string} id The publication id.
			 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
			 */
			handler(id) {
				if (id) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * The endpoint for one move on this publication.
		 *
		 * @param {string} path The path after the publication id.
		 * @return {string} The URL.
		 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
		 */
		url(path) {
			return generateUrl('/apps/opencatalogi/api/publications/{id}/' + path, {
				id: this.resolvedObjectId,
			})
		},

		/**
		 * Read the state, and the documents a single withdrawal can pick from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
		 */
		async load() {
			try {
				const { data } = await axios.get(this.url('visibility'))
				this.state = data.state || ''
			} catch {
				this.state = ''
			}

			await this.loadFiles()
		},

		/**
		 * Read the publication's files: the documents a withdrawal can pick
		 * from, and the ones already withdrawn.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
		 */
		async loadFiles() {
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}/{id}/files',
						filesRouteParams({
							register: this.register,
							schema: this.schema,
							ctx: this.ctx,
							id: this.resolvedObjectId,
						}),
					),
				)
				this.documents = documentOptions(data)
				this.withdrawn = withdrawnDocuments(data)
			} catch {
				this.documents = []
				this.withdrawn = []
			}
		},

		/**
		 * Publish now, or publish again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/publications/spec.md#requirement-a-withdrawn-publication-can-be-published-again-req-ppw-003
		 */
		async publish() {
			this.busy = true
			this.message = ''
			try {
				await axios.post(this.url('publish'))
				this.state = 'public'
				this.messageType = 'success'
				this.message = this.t('opencatalogi', 'The publication is public.')
			} catch (error) {
				this.showError(error)
			} finally {
				this.busy = false
			}
		},

		/** @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002 */
		openWithdraw() {
			this.message = ''
			this.dialogOpen = true
		},

		/**
		 * Withdraw the publication, or the one document chosen.
		 *
		 * @param {{reason: string, fileId: string}} answer What the dialog collected.
		 * @return {Promise<void>}
		 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
		 */
		async withdraw(answer) {
			this.busy = true
			try {
				const { data } = await axios.post(this.url(withdrawPath(answer)), {
					reason: answer.reason,
				})
				this.dialogOpen = false
				if (answer.fileId) {
					this.showDocumentWithdrawn()
					return
				}
				this.state = 'withdrawn'
				this.showWithdrawn(data.outstandingChannels || [])
			} catch (error) {
				this.dialogOpen = false
				this.showError(error)
			} finally {
				this.busy = false
			}
		},

		/** @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002 */
		showDocumentWithdrawn() {
			this.messageType = 'success'
			this.message = this.t(
				'opencatalogi',
				'The document was withdrawn. The rest of the publication stays public.',
			)
			this.loadFiles()
		},

		/**
		 * Ask why a withdrawn document is put back.
		 *
		 * @param {{id: (string|number), label: string}} document The withdrawn document.
		 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
		 */
		openRepublish(document) {
			this.message = ''
			this.republishing = document
		},

		/**
		 * Put the chosen withdrawn document back, with the reason, and read the files again.
		 *
		 * @param {{reason: string}} answer What the dialog collected.
		 * @return {Promise<void>}
		 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
		 */
		async republish(answer) {
			const document = this.republishing
			this.busy = true
			try {
				await axios.post(
					this.url('files/' + encodeURIComponent(document.id) + '/republish'),
					{ reason: answer.reason },
				)
				this.messageType = 'success'
				this.message = this.t('opencatalogi', 'The document is public again.')
				this.loadFiles()
			} catch (error) {
				this.showError(error)
			} finally {
				this.republishing = null
				this.busy = false
			}
		},

		/**
		 * A moment as a local date, or as it came when it cannot be read.
		 *
		 * @param {string} iso The ISO-8601 moment.
		 * @return {string} The date.
		 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
		 */
		formatDate(iso) {
			const date = new Date(iso)
			return Number.isNaN(date.getTime()) ? String(iso) : date.toLocaleDateString()
		},

		/**
		 * Say the publication was withdrawn, and which channels have not confirmed.
		 *
		 * @param {Array<string>} outstanding The channels still to confirm.
		 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
		 */
		showWithdrawn(outstanding) {
			if (outstanding.length > 0) {
				this.messageType = 'warning'
				this.message = this.t(
					'opencatalogi',
					'Withdrawn. These channels have not confirmed yet: {channels}',
					{ channels: outstanding.join(', ') },
				)
				return
			}
			this.messageType = 'success'
			this.message = this.t(
				'opencatalogi',
				'Withdrawn, and every channel confirmed.',
			)
		},

		/**
		 * Say why a move failed, in the words the server gave when it gave any.
		 *
		 * @param {object} error The axios error.
		 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
		 */
		showError(error) {
			const status = error && error.response ? error.response.status : 0
			this.messageType = 'error'
			if (status === 403) {
				this.message = this.t(
					'opencatalogi',
					'You may not change this publication.',
				)
				return
			}
			if (status === 409) {
				this.message = this.t(
					'opencatalogi',
					'Someone else changed it first. The state shown is the current one.',
				)
				this.load()
				return
			}
			this.message = this.t('opencatalogi', 'That did not work. Try again.')
		},
	},
}
</script>

<style scoped>
.publication-visibility {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.publication-visibility__state {
	margin: 0;
}

.publication-visibility__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.publication-visibility__withdrawn-title {
	margin: 0 0 8px;
}

.publication-visibility__withdrawn-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.publication-visibility__withdrawn-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.publication-visibility__withdrawn-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-main-text);
}
</style>
