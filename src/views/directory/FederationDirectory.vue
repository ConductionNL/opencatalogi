<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// FederationDirectory — data layer + admin surface for the Directory page.
// The manifest's previous `component: "CnFederationStatus"` mounted the
// bare presentational widget with no `:nodes` prop, so it always rendered
// the empty-state regardless of /api/listings content. This wrapper
// fetches the peer listings and renders a compact status summary +
// per-row table with edit / delete affordances. The Add / Edit modals
// are separate isolated files under src/modals/directory/ so
// hydra-gate-modal-isolation is satisfied.
//
// The modal-open flags use a `federation…` prefix so the manifest-v2
// wrappers never collide with the legacy AddDirectoryModal / EditListing
// modals (which key on `'addDirectory'` / `'editListing'`) if both shells
// ever coexist.
//
// References:
//   - WOO-493 manual walkthrough (2026-06-30) — surfaced the missing binding.
//   - WOO-510 — Directory + Search UI binding.
//   - WOO-511 — this change: edit + delete affordances.
//   - src/modals/directory/FederationAddDirectoryModal.vue
//   - src/modals/directory/FederationEditListingModal.vue

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import { navigationStore } from '../../store/store.js'
import FederationAddDirectoryModal from '../../modals/directory/FederationAddDirectoryModal.vue'
import FederationEditListingModal from '../../modals/directory/FederationEditListingModal.vue'
import FederationDeleteListingModal from '../../modals/directory/FederationDeleteListingModal.vue'

export default {
	name: 'FederationDirectory',
	components: {
		NcButton,
		NcActions,
		NcActionButton,
		PencilOutline,
		DeleteOutline,
		DotsHorizontal,
		FederationAddDirectoryModal,
		FederationEditListingModal,
		FederationDeleteListingModal,
	},
	data() {
		return {
			listings: [],
			loading: false,
			error: null,
			editingListing: null,
			deletingListing: null,
		}
	},
	computed: {
		modalState() {
			return navigationStore.modal
		},
		summary() {
			const counts = { up: 0, degraded: 0, down: 0, unknown: 0 }
			for (const l of this.listings) {
				counts[this.statusFor(l)]++
			}
			return counts
		},
	},
	watch: {
		/**
		 * Refresh the listing set whenever any Directory-modal closes,
		 * so a successful add / edit / delete surfaces immediately.
		 *
		 * @param {string|null} next Current modal name (null when closed).
		 * @param {string|null} prev Previous modal name.
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		modalState(next, prev) {
			const dirModals = ['federationAddDirectory', 'federationEditListing', 'federationDeleteListing']
			if (dirModals.includes(prev) && next !== prev) {
				// Also drop the reference to the row-focus object so it doesn't
				// pin the previously-edited listing in memory (WOO-511 PR #79
				// review: `editingListing`/`deletingListing` never cleared).
				if (prev === 'federationEditListing') this.editingListing = null
				if (prev === 'federationDeleteListing') this.deletingListing = null
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
		 * `/api/listings` is a regular AppFramework route, not an OCS one —
		 * no `OCS-APIRequest` header needed here (adding it would be
		 * cargo-cult; the endpoint's CSRF middleware doesn't consult it).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/opencatalogi/api/listings')
				const res = await fetch(url, { headers: { Accept: 'application/json' } })
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
		 * Map a listing record to a discrete status value used for the
		 * summary row and the per-row dot.
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
		 * Localised status-message rendered under each row when the peer
		 * is not fully healthy.
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
		 * Open the add-peer modal.
		 *
		 * @return {void}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		openAdd() {
			navigationStore.setModal('federationAddDirectory')
		},
		/**
		 * Open the edit modal for a specific listing.
		 *
		 * @param {object} listing Listing to edit.
		 * @return {void}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		openEdit(listing) {
			this.editingListing = listing
			navigationStore.setModal('federationEditListing')
		},
		/**
		 * Open the delete-confirmation modal for a listing. The modal itself
		 * performs the DELETE request and closes on success — we react by
		 * re-fetching the list via the modalState watcher above (no
		 * `window.confirm` any more; that used the browser-native dialog and
		 * didn't match NC theming, WOO-511 review feedback).
		 *
		 * @param {object} listing Listing to remove.
		 * @return {void}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-directory-visibility
		 */
		openDelete(listing) {
			this.deletingListing = listing
			navigationStore.setModal('federationDeleteListing')
		},
		/**
		 * Human-readable integration level for the row column. Maps the raw
		 * enum wire value to the same localised label the edit modal uses,
		 * so a user seeing "Federated search" in the row and clicking Edit
		 * finds the same option pre-selected in the dropdown. Unknown values
		 * fall back to the raw string (surfacing peer-side drift without
		 * silently dropping information).
		 *
		 * @param {object} listing Listing record from /api/listings.
		 * @return {string}
		 * @spec exclude presentation-only mapper — no behaviour change on the wire
		 */
		integrationLevelFor(listing) {
			const raw = listing.integrationLevel
			if (raw === undefined || raw === null || raw === '') return t('opencatalogi', 'not set')
			if (raw === 'search') return t('opencatalogi', 'Federated search')
			if (raw === 'sync') return t('opencatalogi', 'Full sync')
			if (raw === 'none') return t('opencatalogi', 'Disabled')
			return raw
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

		<div class="federation-directory__summary" data-testid="federation-directory-summary">
			<span class="federation-directory__summary-item federation-directory__summary-item--up">
				<span aria-hidden="true" class="federation-directory__dot federation-directory__dot--up" />
				{{ summary.up }} {{ t('opencatalogi', 'available') }}
			</span>
			<span class="federation-directory__summary-item federation-directory__summary-item--degraded">
				<span aria-hidden="true" class="federation-directory__dot federation-directory__dot--degraded" />
				{{ summary.degraded }} {{ t('opencatalogi', 'degraded') }}
			</span>
			<span class="federation-directory__summary-item federation-directory__summary-item--down">
				<span aria-hidden="true" class="federation-directory__dot federation-directory__dot--down" />
				{{ summary.down }} {{ t('opencatalogi', 'unreachable') }}
			</span>
			<span v-if="summary.unknown > 0" class="federation-directory__summary-item federation-directory__summary-item--unknown">
				<span aria-hidden="true" class="federation-directory__dot federation-directory__dot--unknown" />
				{{ summary.unknown }} {{ t('opencatalogi', 'unknown') }}
			</span>
		</div>

		<p v-if="loading" class="federation-directory__hint">
			{{ t('opencatalogi', 'Loading federated peers…') }}
		</p>
		<p v-else-if="error" class="federation-directory__error">
			{{ t('opencatalogi', 'Failed to load directory:') }} {{ error }}
		</p>

		<p v-else-if="listings.length === 0" class="federation-directory__hint">
			{{ t('opencatalogi', 'No federation peers registered. Use \'Add directory\' to connect to a peer instance.') }}
		</p>

		<ul v-else class="federation-directory__list">
			<li v-for="listing in listings"
				:key="listing.id"
				class="federation-directory__node"
				:class="'federation-directory__node--' + statusFor(listing)">
				<span aria-hidden="true"
					class="federation-directory__dot"
					:class="'federation-directory__dot--' + statusFor(listing)" />
				<div class="federation-directory__node-info">
					<div class="federation-directory__node-name">
						{{ listing.title || listing.directory || t('opencatalogi', 'Unnamed instance') }}
					</div>
					<div class="federation-directory__node-url">
						{{ listing.directory }}
					</div>
					<div v-if="messageFor(listing)" class="federation-directory__node-message">
						{{ messageFor(listing) }}
					</div>
				</div>
				<div class="federation-directory__node-integration">
					<span class="federation-directory__field-label">
						{{ t('opencatalogi', 'Integration level') }}
					</span>
					<span class="federation-directory__field-value">
						{{ integrationLevelFor(listing) }}
					</span>
				</div>
				<div class="federation-directory__node-actions">
					<NcActions :force-menu="false"
						:aria-label="t('opencatalogi', 'Actions for {name}', { name: listing.title || listing.directory })">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton :close-after-click="true" @click="openEdit(listing)">
							<template #icon>
								<PencilOutline :size="20" />
							</template>
							{{ t('opencatalogi', 'Edit') }}
						</NcActionButton>
						<NcActionButton :close-after-click="true" @click="openDelete(listing)">
							<template #icon>
								<DeleteOutline :size="20" />
							</template>
							{{ t('opencatalogi', 'Remove') }}
						</NcActionButton>
					</NcActions>
				</div>
			</li>
		</ul>

		<FederationAddDirectoryModal />
		<FederationEditListingModal :listing="editingListing" />
		<FederationDeleteListingModal :listing="deletingListing" />
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
}
.federation-directory__title {
	margin: 0;
}
.federation-directory__summary {
	display: flex;
	gap: 16px;
	margin: 8px 0 16px;
	font-size: 13px;
}
.federation-directory__summary-item {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
}
.federation-directory__dot {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-background-darker);
}
.federation-directory__dot--up { background: var(--color-success); }
.federation-directory__dot--degraded { background: var(--color-warning); }
.federation-directory__dot--down { background: var(--color-error); }
.federation-directory__dot--unknown { background: var(--color-text-lighter); }
.federation-directory__hint {
	color: var(--color-text-maxcontrast);
}
.federation-directory__error {
	color: var(--color-error);
}
.federation-directory__list {
	list-style: none;
	padding: 0;
	margin: 0;
	border-top: 1px solid var(--color-border);
}
.federation-directory__node {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 8px;
	border-bottom: 1px solid var(--color-border);
}
.federation-directory__node-info {
	flex: 1;
	min-width: 0;
}
.federation-directory__node-name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.federation-directory__node-url {
	font-size: 12px;
	color: var(--color-text-lighter);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.federation-directory__node-message {
	font-size: 12px;
	color: var(--color-warning);
	margin-top: 2px;
}
.federation-directory__node-integration {
	flex: 0 0 auto;
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	margin-inline: 12px;
	min-width: 100px;
}
.federation-directory__field-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-lighter);
}
.federation-directory__field-value {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
.federation-directory__node-actions {
	flex: 0 0 auto;
}
</style>
