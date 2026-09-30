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

		<NcNoteCard
			v-if="message"
			:type="messageType"
			data-testid="publication-visibility-message">
			{{ message }}
		</NcNoteCard>

		<WithdrawPublicationDialog
			:open="dialogOpen"
			:busy="busy"
			:documents="state === 'public' ? documents : []"
			@close="dialogOpen = false"
			@confirm="withdraw" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import WithdrawPublicationDialog from '../../dialogs/publication/WithdrawPublicationDialog.vue'

/**
 * Publish now, withdraw with a reason, and publish again (REQ-PPW-002/003/004).
 *
 * The state comes from the server, which reads the same dates OpenRegister's
 * published predicate reads, so the page never works it out from dates in the
 * browser. Each button shows only when its move applies.
 */
export default {
	name: 'PublicationVisibilityWidget',
	components: { NcButton, NcNoteCard, WithdrawPublicationDialog },
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
			busy: false,
			dialogOpen: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/** @spec exclude presentational widget adapter; resolves the object context from inject. */
		ctx() {
			const inj =
				this.cnObjectContext
				&& (this.cnObjectContext.value || this.cnObjectContext)
			const holder =
				this.cnDetailObjectContext && this.cnDetailObjectContext.value
			return inj || holder || {}
		},

		/** @spec exclude presentational widget adapter; derives the object id. */
		resolvedObjectId() {
			return this.objectId || this.ctx.objectId || this.content.objectId || ''
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

			try {
				const schema = this.schema || this.ctx.schema || 'publication'
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}/{id}/files',
						{
							register:
								this.register || this.ctx.register || 'publication',
							schema:
								typeof schema === 'string'
									? schema
									: schema.slug || schema.id,
							id: this.resolvedObjectId,
						},
					),
				)
				const files = Array.isArray(data) ? data : data.results || []
				this.documents = files.map((file) => ({
					id: file.id,
					label: file.title || file.name || String(file.id),
				}))
			} catch {
				this.documents = []
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
				const path = answer.fileId
					? 'files/' + encodeURIComponent(answer.fileId) + '/withdraw'
					: 'withdraw'
				const { data } = await axios.post(this.url(path), {
					reason: answer.reason,
				})
				this.dialogOpen = false
				if (answer.fileId) {
					this.messageType = 'success'
					this.message = this.t(
						'opencatalogi',
						'The document was withdrawn. The rest of the publication stays public.',
					)
					return
				}
				this.state = 'withdrawn'
				const outstanding = data.outstandingChannels || []
				this.messageType = outstanding.length > 0 ? 'warning' : 'success'
				this.message =
					outstanding.length > 0
						? this.t(
								'opencatalogi',
								'Withdrawn. These channels have not confirmed yet: {channels}',
								{ channels: outstanding.join(', ') },
							)
						: this.t(
								'opencatalogi',
								'Withdrawn, and every channel confirmed.',
							)
			} catch (error) {
				this.dialogOpen = false
				this.showError(error)
			} finally {
				this.busy = false
			}
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
</style>
