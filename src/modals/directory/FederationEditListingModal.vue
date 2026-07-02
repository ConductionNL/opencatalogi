<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// FederationEditListingModal — edit a peer listing's metadata.
// Currently exposes only the `integrationLevel` field since that's the
// only mutable-and-user-visible one on the current listing schema; the
// URL is set at create-time via /api/listings/add and shouldn't drift.
//
// References:
//   - WOO-511 — Directory edit + delete affordances.
//   - WOO-502 — the partial-PUT semantics this modal relies on.

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcModal, NcButton, NcSelect, NcNoteCard } from '@nextcloud/vue'
import { navigationStore } from '../../store/store.js'

const INTEGRATION_LEVELS = [
	{ value: 'search', label: 'search' },
	{ value: 'sync', label: 'sync' },
	{ value: 'none', label: 'none' },
]

export default {
	name: 'FederationEditListingModal',
	components: { NcModal, NcButton, NcSelect, NcNoteCard },
	props: {
		listing: {
			type: Object,
			default: null,
		},
	},
	emits: ['saved'],
	data() {
		return {
			integrationLevel: null,
			submitting: false,
			error: null,
			integrationLevels: INTEGRATION_LEVELS,
		}
	},
	computed: {
		// Manifest-v2 uses a `federation…` prefix so the flag never collides
		// with the legacy `EditListingModal` (which keys on `'editListing'`)
		// if both shells ever coexist.
		isOpen() {
			return navigationStore.modal === 'federationEditListing'
		},
	},
	watch: {
		isOpen(open) {
			if (open) {
				this.reset()
			}
		},
		listing: {
			immediate: true,
			handler(l) {
				this.integrationLevel = this.integrationLevels.find(
					(o) => o.value === (l?.integrationLevel || 'search'),
				) || this.integrationLevels[0]
			},
		},
	},
	methods: {
		t,
		reset() {
			this.error = null
			this.submitting = false
			this.integrationLevel = this.integrationLevels.find(
				(o) => o.value === (this.listing?.integrationLevel || 'search'),
			) || this.integrationLevels[0]
		},
		close() {
			navigationStore.setModal(null)
		},
		/**
		 * PATCH the listing via PUT /api/listings/{id} (partial per WOO-502).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		async submit() {
			if (!this.listing) {
				return
			}
			this.submitting = true
			this.error = null
			try {
				const endpoint = generateUrl(`/apps/opencatalogi/api/listings/${this.listing.id}`)
				const res = await fetch(endpoint, {
					method: 'PUT',
					headers: {
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
					body: JSON.stringify({ integrationLevel: this.integrationLevel?.value }),
				})
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(body.message || `HTTP ${res.status}`)
				}
				this.$emit('saved', body)
				this.close()
			} catch (e) {
				this.error = e.message || String(e)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<template>
	<NcModal v-if="isOpen && listing"
		label-id="federationEditListingModal"
		@close="close">
		<div class="federation-edit-listing-modal">
			<h2>{{ t('opencatalogi', 'Edit listing') }}</h2>
			<p class="federation-edit-listing-modal__hint">
				{{ listing.title }} — {{ listing.directory }}
			</p>

			<div class="federation-edit-listing-modal__field">
				<label>{{ t('opencatalogi', 'Integration level') }}</label>
				<NcSelect
					v-model="integrationLevel"
					:options="integrationLevels"
					:reduce="(o) => o"
					input-label="integrationLevel"
					:clearable="false" />
			</div>

			<NcNoteCard v-if="error" type="error">
				<p><strong>{{ t('opencatalogi', 'Failed to save listing') }}</strong></p>
				<p>{{ error }}</p>
			</NcNoteCard>

			<div class="federation-edit-listing-modal__actions">
				<NcButton @click="close">
					{{ t('opencatalogi', 'Cancel') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="submitting"
					@click="submit">
					{{ submitting ? t('opencatalogi', 'Saving…') : t('opencatalogi', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<style scoped>
.federation-edit-listing-modal {
	padding: 24px;
	min-width: 480px;
}
.federation-edit-listing-modal__hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 16px;
	font-size: 13px;
	word-break: break-all;
}
.federation-edit-listing-modal__field {
	margin: 16px 0;
}
.federation-edit-listing-modal__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}
.federation-edit-listing-modal__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 24px;
}
</style>
