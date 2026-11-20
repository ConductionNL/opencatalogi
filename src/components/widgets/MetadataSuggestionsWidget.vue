<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="metadata-suggestions" data-testid="metadata-suggestions">
		<ul
			v-if="filled.length > 0"
			class="metadata-suggestions__filled"
			data-testid="metadata-suggestions-filled">
			<li
				v-for="item in filled"
				:key="'filled-' + item.field"
				:data-testid="'metadata-filled-' + item.field">
				<strong>{{ item.label }}:</strong>
				{{
					t(
						'opencatalogi',
						'Filled automatically: the only value you can choose',
					)
				}}
			</li>
		</ul>

		<p
			v-if="loaded && pending.length === 0"
			class="metadata-suggestions__empty"
			data-testid="metadata-suggestions-empty">
			{{ t('opencatalogi', 'No suggestions are waiting.') }}
		</p>

		<ul v-if="pending.length > 0" class="metadata-suggestions__list">
			<li
				v-for="suggestion in pending"
				:key="suggestion.id"
				class="metadata-suggestions__item"
				:data-testid="'metadata-suggestion-' + suggestion.id">
				<p class="metadata-suggestions__value">
					<strong
						>{{ labels[suggestion.field] || suggestion.field }}:</strong
					>
					{{ suggestion.valueLabel || suggestion.value }}
				</p>
				<p class="metadata-suggestions__reason">
					{{ sourceLabel(suggestion.source) }}. {{ suggestion.reason }}
				</p>
				<div v-if="canDecide" class="metadata-suggestions__actions">
					<NcButton
						variant="primary"
						:disabled="busy"
						:data-testid="'metadata-suggestion-accept-' + suggestion.id"
						@click="decide(suggestion, 'accept')">
						{{ t('opencatalogi', 'Accept') }}
					</NcButton>
					<NcButton
						:disabled="busy"
						:data-testid="'metadata-suggestion-reject-' + suggestion.id"
						@click="decide(suggestion, 'reject')">
						{{ t('opencatalogi', 'Reject') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<NcButton
			v-if="hermiq"
			:disabled="busy"
			data-testid="metadata-suggestions-ask-hermiq"
			@click="askHermiq">
			{{ t('opencatalogi', 'Ask Hermiq') }}
		</NcButton>

		<NcNoteCard
			v-if="message"
			:type="messageType"
			data-testid="metadata-suggestions-message">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	decisionPath,
	fieldLabels,
	filledByRule,
	pendingSuggestions,
	refusalMessage,
	sourceLabel,
	suggestionsPath,
} from '../../services/metadataSuggestions.js'
import { injectedObjectContext } from './objectContext.js'

/**
 * The suggestions for a publication's missing Woo metadata (REQ-WMS-001..004).
 *
 * Every pending suggestion shows its value, who made it and why; an editor
 * with the update right accepts or rejects each one, never in bulk. Ask Hermiq
 * shows only when the server says Hermiq can be asked. Fields a rule filled on
 * creation are named as filled automatically.
 */
export default {
	name: 'MetadataSuggestionsWidget',
	components: { NcButton, NcNoteCard },
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
			suggestions: [],
			hermiq: false,
			canDecide: false,
			loaded: false,
			busy: false,
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

		/** @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003 */
		pending() {
			return pendingSuggestions(this.suggestions)
		},

		/** @spec exclude presentational: the field labels. */
		labels() {
			return fieldLabels(this.t)
		},

		/** @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004 */
		filled() {
			return filledByRule(this.ctx.object || null, this.t)
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/**
			 * Load the suggestions once the publication id is known.
			 *
			 * @param {string} id The publication id.
			 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
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
		 * Who made a suggestion, as an editor reads it.
		 *
		 * @param {string} source The source.
		 * @return {string} The label.
		 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
		 */
		sourceLabel(source) {
			return sourceLabel(source, this.t)
		},

		/**
		 * Read the suggestions, and whether Hermiq can be asked and the caller may decide.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
		 */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl(suggestionsPath(this.resolvedObjectId)),
				)
				this.suggestions = data.suggestions || []
				this.hermiq = data.hermiq === true
				this.canDecide = data.canDecide === true
			} catch {
				this.suggestions = []
				this.hermiq = false
				this.canDecide = false
			}
			this.loaded = true
		},

		/**
		 * Accept or reject one suggestion, then read the list again.
		 *
		 * @param {object} suggestion The suggestion.
		 * @param {'accept'|'reject'} decision The decision.
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
		 */
		async decide(suggestion, decision) {
			this.busy = true
			this.message = ''
			try {
				await axios.post(generateUrl(decisionPath(suggestion.id, decision)))
				this.messageType = 'success'
				this.message =
					decision === 'accept'
						? this.t(
								'opencatalogi',
								'Accepted: the value is on the publication.',
							)
						: this.t(
								'opencatalogi',
								'Rejected: the publication is unchanged.',
							)
			} catch (error) {
				this.messageType = 'error'
				this.message = refusalMessage(error, this.t)
			} finally {
				this.busy = false
			}
			await this.load()
		},

		/**
		 * Ask Hermiq for suggestions; the ones it adds join the list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
		 */
		async askHermiq() {
			this.busy = true
			this.message = ''
			try {
				const { data } = await axios.post(
					generateUrl(suggestionsPath(this.resolvedObjectId)),
					{ source: 'hermiq' },
				)
				const added = (data.suggestions || []).length
				this.messageType = added > 0 ? 'success' : 'info'
				this.message =
					added > 0
						? this.t(
								'opencatalogi',
								'Suggestions Hermiq added: {count}',
								{ count: added },
							)
						: this.t('opencatalogi', 'Hermiq had nothing to add.')
			} catch (error) {
				this.messageType = 'error'
				this.message = refusalMessage(error, this.t)
			} finally {
				this.busy = false
			}
			await this.load()
		},
	},
}
</script>

<style scoped>
.metadata-suggestions {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.metadata-suggestions__list,
.metadata-suggestions__filled {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.metadata-suggestions__item {
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.metadata-suggestions__value,
.metadata-suggestions__reason,
.metadata-suggestions__empty {
	margin: 0;
}

.metadata-suggestions__reason {
	color: var(--color-text-maxcontrast);
}

.metadata-suggestions__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}
</style>
