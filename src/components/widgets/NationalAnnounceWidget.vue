<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="national-announce" data-testid="national-announce">
		<h3 class="national-announce__title">
			{{ t('opencatalogi', 'Official notice') }}
		</h3>

		<p v-if="!isAdmin" class="national-announce__hint">
			{{ t('opencatalogi', 'Only an administrator can announce a decision.') }}
		</p>

		<template v-else>
			<p class="national-announce__hint">
				{{
					t(
						'opencatalogi',
						'Announce this decision on the national publication platform and on your own channel.',
					)
				}}
			</p>

			<div class="national-announce__fields">
				<NcSelect
					v-model="publicationType"
					:options="publicationTypeOptions"
					:inputLabel="t('opencatalogi', 'Publication type')"
					:disabled="announcing" />
				<NcTextField
					:modelValue="effectiveDate"
					type="date"
					:label="t('opencatalogi', 'Effective date')"
					:disabled="announcing"
					@update:modelValue="(v) => (effectiveDate = v)" />
			</div>

			<NcButton
				variant="primary"
				:disabled="announcing || !resolvedObjectId"
				data-testid="national-announce-button"
				@click="announce">
				<template #icon>
					<NcLoadingIcon v-if="announcing" :size="20" />
				</template>
				{{ t('opencatalogi', 'Announce') }}
			</NcButton>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<ul
				v-if="results.length > 0"
				class="national-announce__results"
				data-testid="national-announce-results">
				<li v-for="result in results" :key="result.channel">
					<strong>{{ channelLabel(result.channel) }}</strong
					>:
					<span v-if="result.delivered">
						{{ t('opencatalogi', 'Delivered')
						}}<template v-if="result.identifier">
							({{ result.identifier }})
						</template>
					</span>
					<span v-else>
						{{
							t('opencatalogi', 'Not delivered: {reason}', {
								reason: result.reason,
							})
						}}
					</span>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

/**
 * The Announce action on the publication page (REQ-WND-004).
 *
 * Reads the current publication from the detail-page context, asks the
 * administrator for the publication type and the effective date the national
 * gateway needs, and posts the decision to the announce endpoint. The result
 * lists every channel with what it answered, including the ones that were not
 * reached, because a partial announcement must not read as a complete one.
 */
export default {
	name: 'NationalAnnounceWidget',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField },
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
			publicationType: null,
			effectiveDate: '',
			announcing: false,
			error: '',
			results: [],
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

		/** @spec exclude presentational widget adapter; derives the register. */
		resolvedRegister() {
			return (
				this.register
				|| this.ctx.register
				|| this.content.register
				|| 'publication'
			)
		},

		/** @spec exclude presentational widget adapter; derives the schema slug. */
		resolvedSchema() {
			const s =
				this.schema
				|| this.ctx.schema
				|| this.content.schema
				|| 'publication'
			return typeof s === 'string'
				? s
				: (s && (s.slug || s.name || s.id)) || ''
		},

		/** @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-the-announce-endpoint-has-a-screen-req-wnd-004 */
		isAdmin() {
			const user = getCurrentUser()
			return Boolean(user && user.isAdmin)
		},

		/** @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-the-announce-endpoint-has-a-screen-req-wnd-004 */
		publicationTypeOptions() {
			return [
				{
					value: 'gemeenteblad',
					label: this.t('opencatalogi', 'Municipal gazette'),
				},
				{
					value: 'provincieblad',
					label: this.t('opencatalogi', 'Provincial gazette'),
				},
				{
					value: 'waterschapsblad',
					label: this.t('opencatalogi', 'Water authority gazette'),
				},
				{
					value: 'staatscourant',
					label: this.t('opencatalogi', 'Government gazette'),
				},
			]
		},
	},

	methods: {
		/**
		 * The readable name of a channel.
		 *
		 * @param {string} channel The channel name.
		 * @return {string} The label.
		 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-the-announce-endpoint-has-a-screen-req-wnd-004
		 */
		channelLabel(channel) {
			if (channel === 'national-publication-platform') {
				return this.t('opencatalogi', 'National publication platform')
			}
			if (channel === 'local-channel') {
				return this.t('opencatalogi', 'Own channel')
			}
			return channel
		},

		/**
		 * Load the publication and announce it on every channel.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-the-announce-endpoint-has-a-screen-req-wnd-004
		 */
		async announce() {
			this.announcing = true
			this.error = ''
			this.results = []

			try {
				const { data: publication } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}/{id}',
						{
							register: this.resolvedRegister,
							schema: this.resolvedSchema,
							id: this.resolvedObjectId,
						},
					),
				)

				const type = this.publicationType ? this.publicationType.value : ''
				const decision = {
					...publication,
					id: this.resolvedObjectId,
					url: window.location.href,
					publicationType: type,
					effectiveDate: this.effectiveDate,
				}

				let body
				try {
					const response = await axios.post(
						generateUrl('/apps/opencatalogi/api/publications/announce'),
						{ decision },
					)
					body = response.data
				} catch (error) {
					// A channel that could not be reached answers 502 with the same body.
					if (
						error.response
						&& error.response.data
						&& error.response.data.notices
					) {
						body = error.response.data
					} else {
						throw error
					}
				}

				this.results = this.toResults(body)
			} catch {
				this.error = this.t(
					'opencatalogi',
					'The decision could not be announced. Try again.',
				)
			} finally {
				this.announcing = false
			}
		},

		/**
		 * One line per channel, delivered or not.
		 *
		 * @param {object} body The announce response.
		 * @return {Array<object>} The results.
		 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-the-announce-endpoint-has-a-screen-req-wnd-004
		 */
		toResults(body) {
			const delivered = (body.delivered || []).map((entry) => ({
				channel: entry.channel,
				delivered: true,
				identifier: entry.delivery ? entry.delivery.identifier : '',
				reason: '',
			}))
			const unreachable = (body.unreachable || []).map((entry) => ({
				channel: entry.channel,
				delivered: false,
				identifier: '',
				reason: entry.reason,
			}))
			return [...delivered, ...unreachable]
		},
	},
}
</script>

<style scoped>
.national-announce {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.national-announce__title {
	margin: 0;
}

.national-announce__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.national-announce__fields {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.national-announce__results {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}
</style>
