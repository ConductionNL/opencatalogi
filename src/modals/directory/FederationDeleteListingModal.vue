<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// NC-styled confirmation modal for removing a peer listing (replaces the
// earlier browser `window.confirm`). Same pattern as
// src/modals/menuItem/DeleteMenuItemModal.vue.

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
			return this.listing?.title || this.listing?.directory || t('opencatalogi', 'Unnamed instance')
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
				// Delay close so the success note is visible; timer handle is
				// tracked so beforeDestroy() can cancel it (PR #79 review).
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
		:name="t('opencatalogi', 'Remove from directory')"
		@close="close">
		<div class="federation-delete-listing-modal">
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>{{ t('opencatalogi', 'Removed from directory') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="success === false" type="error">
					<p>{{ t('opencatalogi', 'Removal failed') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>

			<p v-if="success === null && !loading">
				{{ t('opencatalogi', 'Remove {name} from the directory? This instance will stop syncing publications from {name}. {name} itself is not affected. This action cannot be undone.', { name: listingName }) }}
			</p>

			<div v-if="loading" class="federation-delete-listing-modal__loading">
				<NcLoadingIcon :size="20" />
				<span>{{ t('opencatalogi', 'Removing…') }}</span>
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
