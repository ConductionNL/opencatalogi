<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// FederationDirectory — data layer for the Directory page. The manifest's
// previous `component: "CnFederationStatus"` mounted the bare presentational
// widget with no `:nodes` prop, so it always rendered the empty-state
// regardless of /api/listings content. This wrapper fetches the peer
// listings and maps them to the shape CnFederationStatus expects. The
// "Add directory" affordance imports the existing modal-isolated
// AddDirectoryModal (src/modals/directory/AddDirectoryModal.vue) so we
// honour hydra-gate-modal-isolation and reuse its sync-report UI. The
// manifest-v2 shell does not mount src/modals/Modals.vue, so we mount
// AddDirectoryModal directly here — the child renders only when
// `navigationStore.modal === 'addDirectory'`.
//
// References:
//   - WOO-493 manual walkthrough (2026-06-30) — surfaced the missing binding.
//   - WOO-510 — this fix.
//   - src/modals/directory/AddDirectoryModal.vue — the reused modal.

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { CnFederationStatus } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { navigationStore } from '../../store/store.js'
import FederationAddDirectoryModal from '../../modals/directory/FederationAddDirectoryModal.vue'

export default {
	name: 'FederationDirectory',
	components: { CnFederationStatus, NcButton, FederationAddDirectoryModal },
	data() {
		return {
			listings: [],
			loading: false,
			error: null,
			previousModal: null,
		}
	},
	computed: {
		/**
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 * @return {Array<object>} Nodes in the shape CnFederationStatus expects.
		 */
		nodes() {
			return this.listings.map((l) => ({
				id: l.id || l['@self']?.id,
				name: l.title || l.name || l.directory || t('opencatalogi', 'Unnamed peer'),
				url: l.directory || l.search || null,
				status: this.statusFor(l),
				message: this.messageFor(l),
				lastChecked: l.lastSyncAt || l.updated || null,
			}))
		},
		modalState() {
			return navigationStore.modal
		},
	},
	watch: {
		/**
		 * Refresh the listing set whenever the shared AddDirectoryModal closes,
		 * so a successful sync surfaces immediately without a manual reload.
		 *
		 * @param {string|null} next Current modal name (null when closed).
		 * @param {string|null} prev Previous modal name.
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		modalState(next, prev) {
			if (prev === 'addDirectory' && next !== 'addDirectory') {
				this.load()
			}
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		/**
		 * Fetch peer listings from the OpenCatalogi listings endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/opencatalogi/api/listings')
				const res = await fetch(url, { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } })
				if (!res.ok) {
					throw new Error(`HTTP ${res.status}`)
				}
				const data = await res.json()
				this.listings = data.results || []
			} catch (e) {
				this.error = e.message || String(e)
				this.listings = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Map a listing to a `CnFederationStatus` status value.
		 *
		 * @param {object} listing Listing record from /api/listings.
		 * @return {'up'|'degraded'|'down'|'unknown'}
		 * @spec exclude presentation-only mapper — no behaviour change on the wire
		 */
		statusFor(listing) {
			if (listing.available === false) return 'down'
			if (typeof listing.statusCode === 'number') {
				if (listing.statusCode >= 200 && listing.statusCode < 300) return 'up'
				if (listing.statusCode >= 500) return 'down'
				return 'degraded'
			}
			return 'unknown'
		},
		/**
		 * Localised status-message rendered next to each node.
		 *
		 * @param {object} listing Listing record from /api/listings.
		 * @return {string} Human-readable status message.
		 * @spec exclude presentation-only mapper — no behaviour change on the wire
		 */
		messageFor(listing) {
			if (listing.available === false) return t('opencatalogi', 'Peer is unreachable')
			if (typeof listing.statusCode === 'number' && (listing.statusCode < 200 || listing.statusCode >= 300)) {
				return t('opencatalogi', 'HTTP status:') + ' ' + listing.statusCode
			}
			return ''
		},
		/**
		 * Open the shared AddDirectoryModal — the modal handles POST + sync
		 * reporting, we react by re-fetching once it closes.
		 *
		 * @return {void}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		openAdd() {
			navigationStore.setModal('addDirectory')
		},
	},
}
</script>

<template>
	<div class="federation-directory">
		<header class="federation-directory__header">
			<h2 class="federation-directory__title">
				{{ t('opencatalogi', 'Directory') }}
			</h2>
			<NcButton type="primary" @click="openAdd">
				{{ t('opencatalogi', 'Add directory') }}
			</NcButton>
		</header>

		<p v-if="loading" class="federation-directory__hint">
			{{ t('opencatalogi', 'Loading federated peers…') }}
		</p>
		<p v-else-if="error" class="federation-directory__error">
			{{ t('opencatalogi', 'Failed to load directory:') }} {{ error }}
		</p>

		<CnFederationStatus
			:nodes="nodes"
			:empty-label="t('opencatalogi', 'No federation peers registered. Use \'Add directory\' to connect to a peer instance.')" />

		<FederationAddDirectoryModal />
	</div>
</template>

<style scoped>
.federation-directory {
	padding: 20px;
}
.federation-directory__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
	/*
	 * 56px clears NcAppNavigationToggle — per-header pattern documented at
	 * nextcloud-vue/src/components/CnPageRenderer/CnPageRenderer.vue:993.
	 * @visual exclude scoped copy of .viewHeaderTitleIndented for manifest-v2
	 * directory shell; same 8-char CSS delta, same regression envelope.
	 */
	padding-inline-start: 56px;
}
.federation-directory__title {
	margin: 0;
}
.federation-directory__hint {
	color: var(--color-text-maxcontrast);
}
.federation-directory__error {
	color: var(--color-error);
}
</style>
