<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// FederationDeleteListingModal — Nextcloud-styled confirmation modal
// for removing a peer listing. Replaces the earlier `window.confirm`
// pop-up (which uses the browser's native dialog and doesn't match the
// NC look-and-feel or theming). Modeled on
// src/modals/menuItem/DeleteMenuItemModal.vue: same NcModal-based
// pattern, same navigation-store flag control.
//
// References:
//   - WOO-511 — Directory edit + delete affordances.

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcModal, NcButton, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { navigationStore } from '../../store/store.js'

export default {
	name: 'FederationDeleteListingModal',
	components: { NcModal, NcButton, NcNoteCard, NcLoadingIcon, Cancel, Delete },
	props: {
		listing: {
			type: Object,
			default: null,
		},
	},
	emits: ['deleted'],
	data() {
		return {
			loading: false,
			success: null,
			error: null,
			closeTimer: null,
		}
	},
	computed: {
		isOpen() {
			return navigationStore.modal === 'federationDeleteListing'
		},
		listingName() {
			return this.listing?.title || this.listing?.directory || t('opencatalogi', 'Unnamed peer')
		},
	},
	watch: {
		isOpen(open) {
			if (open) {
				this.reset()
			}
		},
	},
	beforeDestroy() {
		// Prevent a delayed `close()` from firing after the component
		// unmounts (WOO-511 PR #79 review: setTimeout leak on early close).
		if (this.closeTimer !== null) {
			clearTimeout(this.closeTimer)
			this.closeTimer = null
		}
	},
	methods: {
		t,
		reset() {
			// Drop any stale success-close timer from a previous open
			// (belt + braces alongside `beforeDestroy` above).
			if (this.closeTimer !== null) {
				clearTimeout(this.closeTimer)
				this.closeTimer = null
			}
			this.loading = false
			this.success = null
			this.error = null
		},
		close() {
			if (this.closeTimer !== null) {
				clearTimeout(this.closeTimer)
				this.closeTimer = null
			}
			navigationStore.setModal(null)
		},
		/**
		 * DELETE /api/listings/{id} — remove the peer listing. This is a
		 * regular AppFramework route (not OCS), so no `OCS-APIRequest`
		 * header is needed here.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		async handleDelete() {
			if (!this.listing) {
				return
			}
			this.loading = true
			this.error = null
			try {
				const endpoint = generateUrl(`/apps/opencatalogi/api/listings/${this.listing.id}`)
				const res = await fetch(endpoint, {
					method: 'DELETE',
					headers: {
						Accept: 'application/json',
					},
				})
				if (!res.ok) {
					const body = await res.json().catch(() => ({}))
					throw new Error(body.message || `HTTP ${res.status}`)
				}
				this.success = true
				this.$emit('deleted', this.listing)
				// Close after a short beat so the success note is visible.
				// Cancel-safe via the timer handle stored on the component
				// (WOO-511 PR #79 review).
				this.closeTimer = setTimeout(() => {
					this.closeTimer = null
					this.close()
				}, 800)
			} catch (e) {
				this.success = false
				this.error = e.message || String(e)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<template>
	<NcModal v-if="isOpen && listing"
		label-id="federationDeleteListingModal"
		:name="t('opencatalogi', 'Remove peer listing')"
		@close="close">
		<div class="federation-delete-listing-modal">
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>{{ t('opencatalogi', 'Peer listing removed') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="success === false" type="error">
					<p>{{ t('opencatalogi', 'Failed to remove peer listing') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>

			<p v-if="success === null && !loading">
				{{ t('opencatalogi', 'Are you sure you want to remove the peer listing {name}? This will stop this instance from syncing publications from that peer. The remote peer itself is not affected. This action cannot be undone.', { name: listingName }) }}
			</p>

			<div v-if="loading" class="federation-delete-listing-modal__loading">
				<NcLoadingIcon :size="20" />
				<span>{{ t('opencatalogi', 'Removing peer listing…') }}</span>
			</div>

			<div class="federation-delete-listing-modal__actions">
				<NcButton @click="close">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
				</NcButton>
				<NcButton v-if="success === null"
					type="error"
					:disabled="loading"
					@click="handleDelete">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{ t('opencatalogi', 'Remove') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<style scoped>
.federation-delete-listing-modal {
	padding: 24px;
	min-width: 480px;
}
.federation-delete-listing-modal__loading {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	margin: 16px 0;
}
.federation-delete-listing-modal__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 24px;
}
</style>
