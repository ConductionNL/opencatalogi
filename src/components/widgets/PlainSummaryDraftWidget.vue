<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div
		v-if="available"
		class="plain-summary-draft"
		data-testid="plain-summary-draft">
		<NcButton
			v-if="!suggestion"
			variant="secondary"
			:disabled="busy"
			data-testid="plain-summary-draft-start"
			@click="startDraft">
			{{ t('opencatalogi', 'Draft plain summary') }}
		</NcButton>
		<p
			v-if="busy && !suggestion"
			class="plain-summary-draft__hint"
			data-testid="plain-summary-draft-waiting">
			{{ t('opencatalogi', 'The text assistant is writing a suggestion.') }}
		</p>

		<template v-if="suggestion">
			<p class="plain-summary-draft__hint">
				{{ t('opencatalogi', 'This is a suggestion. It is saved only when you accept it.') }}
			</p>
			<NcTextArea
				v-model="suggestion"
				:label="t('opencatalogi', 'Suggested plain language summary')"
				data-testid="plain-summary-draft-text" />
			<NcSelect
				v-model="level"
				:options="levels"
				:inputLabel="t('opencatalogi', 'Reading level')"
				:clearable="false"
				data-testid="plain-summary-draft-level" />
			<div class="plain-summary-draft__actions">
				<NcButton
					variant="primary"
					:disabled="busy || !suggestion.trim()"
					data-testid="plain-summary-draft-accept"
					@click="accept">
					{{ t('opencatalogi', 'Accept') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="busy"
					data-testid="plain-summary-draft-discard"
					@click="discard">
					{{ t('opencatalogi', 'Discard') }}
				</NcButton>
			</div>
		</template>

		<NcNoteCard
			v-if="message"
			:type="messageType"
			data-testid="plain-summary-draft-message">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextArea } from '@nextcloud/vue'
import { injectedObjectContext } from './objectContext.js'

/** Milliseconds between two polls of the draft task. */
export const POLL_INTERVAL = 2000

/** The most polls before the page stops waiting. */
export const MAX_POLLS = 90

/**
 * Draft a plain language summary with the text assistant, then accept or discard it (REQ-PPL-002).
 *
 * Shown only when the server says a text assistant is available. A draft is
 * a suggestion: nothing is stored until the officer accepts it, with a level.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md
 */
export default {
	name: 'PlainSummaryDraftWidget',
	components: { NcButton, NcNoteCard, NcSelect, NcTextArea },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},

	props: {
		objectId: { type: String, default: '' },
		content: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			available: false,
			busy: false,
			taskId: null,
			suggestion: '',
			level: 'B1',
			levels: ['A2', 'B1', 'B2'],
			polls: 0,
			timer: null,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/** @spec exclude presentational widget adapter — resolves the injected context and object id. */
		ctx() {
			return injectedObjectContext(this)
		},

		/** @spec exclude presentational widget adapter — resolves the injected context and object id. */
		resolvedObjectId() {
			return this.objectId || this.ctx.objectId || this.content.objectId || ''
		},
	},

	mounted() {
		this.checkAssistant()
	},

	beforeUnmount() {
		this.stopPolling()
	},

	methods: {
		/**
		 * The API path of this publication's plain summary routes.
		 *
		 * @param {string} tail The path after the publication id.
		 * @return {string} The URL.
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		url(tail) {
			return generateUrl('/apps/opencatalogi/api/publications/' + encodeURIComponent(this.resolvedObjectId) + '/' + tail)
		},

		/**
		 * Ask whether a text assistant is available; without one the widget stays hidden.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		async checkAssistant() {
			try {
				const { data } = await axios.get(generateUrl('/apps/opencatalogi/api/plain-summary/assistant'))
				this.available = data.available === true
			} catch {
				this.available = false
			}
		},

		/**
		 * Schedule a draft and start polling it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		async startDraft() {
			this.busy = true
			this.message = ''
			try {
				const { data } = await axios.post(this.url('plain-summary-draft'))
				this.taskId = data.taskId
				this.polls = 0
				this.schedulePoll()
			} catch (error) {
				this.fail(error)
			}
		},

		/**
		 * Poll again after the interval.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		schedulePoll() {
			this.timer = setTimeout(() => this.poll(), POLL_INTERVAL)
		},

		/**
		 * Stop a pending poll.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		stopPolling() {
			if (this.timer !== null) {
				clearTimeout(this.timer)
				this.timer = null
			}
		},

		/**
		 * Read the draft task once and act on its status.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		async poll() {
			this.timer = null
			this.polls++
			try {
				const { data } = await axios.get(this.url('plain-summary-draft/' + this.taskId))
				this.take(data)
			} catch (error) {
				this.fail(error)
			}
		},

		/**
		 * Take a poll answer: the suggestion, the failure, or poll again.
		 *
		 * @param {{status: string, text?: string, error?: string}} data The answer.
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		take(data) {
			if (data.status === 'successful') {
				this.suggestion = data.text || ''
				this.busy = false
				return
			}
			const error = this.pollError(data)
			if (error !== null) {
				this.showError(error)
				return
			}
			this.schedulePoll()
		},

		/**
		 * The error a poll answer ends with: the task failed, or it took too many polls.
		 *
		 * @param {{status: string, error?: string}} data The answer.
		 * @return {string|null} The error, or null to poll again.
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		pollError(data) {
			if (data.status === 'failed') {
				return data.error || this.t('opencatalogi', 'The text assistant could not write a suggestion.')
			}
			if (this.polls >= MAX_POLLS) {
				return this.t('opencatalogi', 'The text assistant is taking too long. Try again later.')
			}
			return null
		},

		/**
		 * Store the suggestion, as edited, with its level.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		async accept() {
			this.busy = true
			try {
				await axios.post(this.url('plain-summary'), {
					text: this.suggestion,
					level: this.level,
					fromTaskId: this.taskId,
				})
				this.accepted()
			} catch (error) {
				this.fail(error)
			}
		},

		/**
		 * Clear the suggestion after it was stored and say so.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		accepted() {
			this.suggestion = ''
			this.taskId = null
			this.busy = false
			this.messageType = 'success'
			this.message = this.t('opencatalogi', 'The plain language summary is saved.')
		},

		/**
		 * Drop the suggestion; nothing was stored.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		discard() {
			this.stopPolling()
			this.suggestion = ''
			this.taskId = null
			this.busy = false
			this.message = ''
		},

		/**
		 * Show the refusal the server gave, or a general one.
		 *
		 * @param {object} error The request error.
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		fail(error) {
			const data = error && error.response ? error.response.data : null
			this.showError((data && data.message) || this.t('opencatalogi', 'That did not work. Try again.'))
		},

		/**
		 * Stop waiting and show an error.
		 *
		 * @param {string} text The error.
		 * @return {void}
		 *
		 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
		 */
		showError(text) {
			this.busy = false
			this.messageType = 'error'
			this.message = text
		},
	},
}
</script>

<style scoped>
.plain-summary-draft {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.plain-summary-draft__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.plain-summary-draft__actions {
	display: flex;
	gap: 8px;
}
</style>
