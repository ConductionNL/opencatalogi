<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Edit a peer listing's metadata. Only `integrationLevel` is exposed;
// the URL is create-time-only via /api/listings/add. Uses WOO-502's
// partial-PUT semantics.

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcModal, NcButton, NcSelect, NcNoteCard } from '@nextcloud/vue'
import { navigationStore } from '../../store/store.js'

// `value` = wire enum for ListingsController::UPDATABLE_LISTING_FIELDS;
// `label` = the rendering also shown in the row's integrationLevel column.
function buildIntegrationLevels() {
	return [
		{ value: 'search', label: t('opencatalogi', 'Federated search') },
		{ value: 'sync', label: t('opencatalogi', 'Full sync') },
		{ value: 'none', label: t('opencatalogi', 'Disabled') },
	]
}

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
			integrationLevels: buildIntegrationLevels(),
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
		/** @spec exclude review-driven UI hardening for WOO-511 federation directory affordances */
		isOpen(open) {
			if (open) {
				this.reset()
			}
		},
		/** @spec exclude review-driven UI hardening for WOO-511 federation directory affordances */
		listing(l) {
			this.integrationLevel = this.integrationLevels.find(
				(o) => o.value === (l?.integrationLevel || 'search'),
			) || this.integrationLevels[0]
		},
	},
	methods: {
		t,
		/** @spec exclude review-driven UI hardening for WOO-511 federation directory affordances */
		reset() {
			this.error = null
			this.submitting = false
			this.integrationLevel = this.integrationLevels.find(
				(o) => o.value === (this.listing?.integrationLevel || 'search'),
			) || this.integrationLevels[0]
		},
		/** @spec exclude review-driven UI hardening for WOO-511 federation directory affordances */
		close() {
			navigationStore.setModal(null)
		},
		/** @spec exclude review-driven UI hardening for WOO-511 federation directory affordances */
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
					throw new Error(body?.data?.error || body?.error || body?.message || `HTTP ${res.status}`)
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
				{{ listing.title }}
			</p>

			<!--
				The directory URL is intentionally read-only. Changing it here
				would make this listing point at a different peer while
				preserving the old peer's cached sync-state (lastSync,
				statusCode, availability). That's semantically a re-add of a
				new peer, not an edit — the correct workflow is Remove +
				Add directory with the new URL. Rendered as `readonly`
				(not `disabled`) so admins can still select + copy the URL
				and screen readers announce it as a normal text field with
				a value, per WOO-511 PR #79 review.
			-->
			<div class="federation-edit-listing-modal__field">
				<label :for="'federationEditListingUrl-' + (listing.id || 'x')">
					{{ t('opencatalogi', 'Directory URL') }}
				</label>
				<input :id="'federationEditListingUrl-' + (listing.id || 'x')"
					type="url"
					:value="listing.directory"
					readonly
					class="federation-edit-listing-modal__readonly">
				<span class="federation-edit-listing-modal__readonly-hint">
					{{ t('opencatalogi', 'Directory URL is read-only. To point at a different peer, remove this listing and add the new URL — the cached sync-state belongs to the current peer identity.') }}
				</span>
			</div>

			<div class="federation-edit-listing-modal__field">
				<label :for="'federationEditListingIntegration-' + (listing.id || 'x')">
					{{ t('opencatalogi', 'Integration level') }}
				</label>
				<NcSelect
					:id="'federationEditListingIntegration-' + (listing.id || 'x')"
					v-model="integrationLevel"
					:options="integrationLevels"
					:input-label="t('opencatalogi', 'Integration level')"
					:reduce="(o) => o"
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
.federation-edit-listing-modal__readonly {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-text-lighter);
	font-family: monospace;
	font-size: 13px;
}
.federation-edit-listing-modal__readonly-hint {
	display: block;
	margin-top: 6px;
	font-size: 12px;
	color: var(--color-text-lighter);
}
.federation-edit-listing-modal__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 24px;
}
</style>
