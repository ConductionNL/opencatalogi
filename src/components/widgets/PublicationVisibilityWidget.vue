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
		<p
			v-if="unlisted"
			class="publication-visibility__state"
			data-testid="publication-visibility-unlisted">
			{{ t('opencatalogi', 'Unlisted: reachable by its link, left out of search and feeds.') }}
		</p>
		<p
			v-if="state"
			class="publication-visibility__audience"
			data-testid="publication-visibility-audience">
			<strong>{{ t('opencatalogi', 'Who can read it') }}:</strong>
			{{ audienceLabel }}
		</p>
		<p
			v-if="audience === 'signedIn'"
			class="publication-visibility__audience-link"
			data-testid="publication-visibility-audience-link">
			{{ t('opencatalogi', 'The link works for readers who log in') }}
		</p>

		<NcNoteCard
			v-if="sourceAbsenceNote"
			type="warning"
			data-testid="publication-source-absence">
			{{ sourceAbsenceNote }}
		</NcNoteCard>

		<div class="publication-visibility__actions">
			<NcButton
				v-if="actions.publishNow"
				variant="primary"
				:disabled="busy"
				data-testid="publication-publish-now"
				@click="publish">
				{{ t('opencatalogi', 'Publish now') }}
			</NcButton>
			<NcButton
				v-if="actions.publishAgain"
				variant="primary"
				:disabled="busy"
				data-testid="publication-publish-again"
				@click="publish">
				{{ t('opencatalogi', 'Publish again') }}
			</NcButton>
			<NcButton
				v-if="lists.unlist"
				:disabled="busy"
				data-testid="publication-unlist"
				@click="setListed(false)">
				{{ t('opencatalogi', 'Unlist') }}
			</NcButton>
			<NcButton
				v-if="lists.listAgain"
				:disabled="busy"
				data-testid="publication-list-again"
				@click="setListed(true)">
				{{ t('opencatalogi', 'List again') }}
			</NcButton>
			<NcButton
				v-if="actions.withdraw"
				variant="error"
				:disabled="busy"
				data-testid="publication-withdraw"
				@click="openWithdraw">
				{{ t('opencatalogi', 'Withdraw') }}
			</NcButton>
			<NcButton
				v-if="deletable"
				variant="error"
				:disabled="busy"
				data-testid="publication-delete-permanently"
				@click="openPurge">
				{{ t('opencatalogi', 'Delete permanently') }}
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
		<DeleteDraftPermanentlyDialog
			:open="purgeOpen"
			:busy="busy"
			:title="publicationTitle"
			@close="purgeOpen = false"
			@confirm="purge" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import DeleteDraftPermanentlyDialog from '../../dialogs/publication/DeleteDraftPermanentlyDialog.vue'
import RepublishDocumentDialog from '../../dialogs/publication/RepublishDocumentDialog.vue'
import WithdrawPublicationDialog from '../../dialogs/publication/WithdrawPublicationDialog.vue'
import {
	canDeletePermanently,
	documentOptions,
	draftPurgeMessage,
	filesRouteParams,
	listActions,
	publishRefusalMessage,
	visibilityActions,
	withdrawnDocuments,
} from '../../services/publicationVisibility.js'
import { injectedObjectContext } from './objectContext.js'

/**
 * A moment from the server, as the reader's locale writes a date and time.
 *
 * @param {string} value An ISO 8601 moment.
 * @return {string} The formatted moment, or the value itself when it is not a date.
 */
function formatMoment(value) {
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return value
	}
	return date.toLocaleString()
}

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
 * The withdraw request's body: the reason, and for the whole publication what
 * its public tombstone says (REQ-PWA-002).
 *
 * @param {{reason: string, fileId: string, publicReason?: string, tombstoneShowsTitle?: boolean}} answer What the dialog collected.
 * @return {object} The body.
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-2.1
 */
function withdrawBody(answer) {
	const body = { reason: answer.reason }
	if (answer.fileId) {
		return body
	}
	if (answer.publicReason) {
		body.publicReason = answer.publicReason
	}
	if (typeof answer.tombstoneShowsTitle === 'boolean') {
		body.tombstoneShowsTitle = answer.tombstoneShowsTitle
	}
	return body
}

/**
 * Publish now, withdraw with a reason, and publish again (REQ-PPW-002/003/004).
 *
 * The state comes from the server, which reads the same dates OpenRegister's
 * published predicate reads, so the page never works it out from dates in the
 * browser. Each button shows only when its move applies, and only to a caller
 * the server says may publish (REQ-OPR-002).
 */
export default {
	name: 'PublicationVisibilityWidget',
	components: { DeleteDraftPermanentlyDialog, NcButton, NcNoteCard, RepublishDocumentDialog, WithdrawPublicationDialog },
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
			canPublish: false,
			canUpdate: false,
			unlisted: false,
			audience: 'public',
			minimumAssurance: 'substantial',
			sourceAbsence: null,
			documents: [],
			withdrawn: [],
			republishing: null,
			busy: false,
			dialogOpen: false,
			purgeOpen: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/** @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-screen-offers-publish-actions-only-to-publishers-req-opr-002 */
		actions() {
			return visibilityActions(this.state, this.canPublish)
		},

		/** @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-public-publication-can-be-unlisted-and-put-back-req-plc-006 */
		lists() {
			return listActions(this.state, this.unlisted, this.canUpdate)
		},

		/** @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2 */
		deletable() {
			return canDeletePermanently(this.state, this.canUpdate)
		},

		/**
		 * The publication's title, as the delete question names it.
		 *
		 * @return {string} The title, or an empty string when the page has none.
		 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
		 */
		publicationTitle() {
			const object = this.ctx.object || this.content || {}
			return String(object.title || object.name || '')
		},

		/** @spec exclude presentational widget adapter; resolves the object context from inject. */
		ctx() {
			return injectedObjectContext(this)
		},

		/** @spec exclude presentational widget adapter; derives the object id. */
		resolvedObjectId() {
			return this.objectId || this.ctx.objectId || this.content.objectId || ''
		},

		/**
		 * What the editor is told when the source this publication was harvested
		 * from no longer carries it: taken down when the source ended it, or kept
		 * until the editor acts.
		 *
		 * @return {string} The note, or an empty string when its source still carries it.
		 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
		 */
		sourceAbsenceNote() {
			const absence = this.sourceAbsence
			if (!absence) {
				return ''
			}
			if (absence.endedAt) {
				return this.t(
					'opencatalogi',
					'Its source no longer carries this publication and ended it on {date}, so it was taken down.',
					{ date: formatMoment(absence.endedAt) },
				)
			}
			if (absence.since) {
				return this.t(
					'opencatalogi',
					'Its source no longer carries this publication (since {date}). It stays as it is until you withdraw it.',
					{ date: formatMoment(absence.since) },
				)
			}
			return this.t(
				'opencatalogi',
				'Its source no longer carries this publication. It stays as it is until you withdraw it.',
			)
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

		/**
		 * Who can read the publication, as the Visibility card shows it (design D5).
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-publication-says-who-can-read-it-req-psr-001
		 */
		audienceLabel() {
			if (this.audience !== 'signedIn') {
				return this.t('opencatalogi', 'Everyone')
			}
			return this.minimumAssurance === 'high'
				? this.t('opencatalogi', 'Readers who log in with DigiD or eHerkenning, level High')
				: this.t('opencatalogi', 'Readers who log in with DigiD or eHerkenning, level Substantial')
		},

		/** @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001 */
		stateLabel() {
			const labels = {
				draft: this.t('opencatalogi', 'Not published yet.'),
				in_review: this.t('opencatalogi', 'In review: waiting for a reviewer.'),
				approved: this.t('opencatalogi', 'Approved: ready to publish.'),
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
				this.canPublish = data.canPublish === true
				this.canUpdate = data.canUpdate === true
				this.unlisted = data.unlisted === true
				this.audience = data.audience === 'signedIn' ? 'signedIn' : 'public'
				this.minimumAssurance = data.minimumAssurance === 'high' ? 'high' : 'substantial'
				this.sourceAbsence = data.sourceAbsence || null
			} catch {
				this.state = ''
				this.canPublish = false
				this.audience = 'public'
				this.minimumAssurance = 'substantial'
				this.sourceAbsence = null
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

		/**
		 * Unlist the publication, or list it again (REQ-PLC-006).
		 *
		 * @param {boolean} listed True to put it back on every surface.
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-public-publication-can-be-unlisted-and-put-back-req-plc-006
		 */
		async setListed(listed) {
			this.busy = true
			this.message = ''
			try {
				const { data } = await axios.post(this.url(listed ? 'list' : 'unlist'))
				this.unlisted = data.unlisted === true
			} catch {
				this.messageType = 'error'
				this.message = this.t('opencatalogi', 'The list status could not be changed.')
			} finally {
				this.busy = false
			}
		},

		/** @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2 */
		openPurge() {
			this.message = ''
			this.purgeOpen = true
		},

		/**
		 * Delete the draft permanently with everything on it, then leave the
		 * page for the catalog's list, since the publication is gone. A refusal
		 * or a stop part way is said in the server's words.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
		 */
		async purge() {
			this.busy = true
			try {
				await axios.delete(this.url('draft'))
				this.purgeOpen = false
				this.state = ''
				this.messageType = 'success'
				this.message = this.t('opencatalogi', 'The publication was deleted permanently.')
				this.leaveDeletedPage()
			} catch (error) {
				this.purgeOpen = false
				this.messageType = 'error'
				this.message = draftPurgeMessage(error) || this.t('opencatalogi', 'That did not work. Try again.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Go to the catalog's publications list when the page knows its catalog.
		 *
		 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
		 */
		leaveDeletedPage() {
			const catalogSlug = this.$route && this.$route.params ? this.$route.params.catalogSlug : ''
			if (this.$router && catalogSlug) {
				this.$router.push({ name: 'Publications', params: { catalogSlug } }).catch(() => {})
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
		 * @param {{reason: string, fileId: string, publicReason?: string, tombstoneShowsTitle?: boolean}} answer What the dialog collected.
		 * @return {Promise<void>}
		 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
		 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-2.1
		 */
		async withdraw(answer) {
			this.busy = true
			try {
				const { data } = await axios.post(this.url(withdrawPath(answer)), withdrawBody(answer))
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
				this.message = publishRefusalMessage(error) || this.t(
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

.publication-visibility__state,
.publication-visibility__audience,
.publication-visibility__audience-link {
	margin: 0;
}

.publication-visibility__audience-link {
	color: var(--color-text-maxcontrast);
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
