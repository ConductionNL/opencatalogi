<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Data layer + admin surface for the Directory page. Replaces the
// manifest's earlier `component: "CnFederationStatus"` binding which
// mounted the presentational widget without a `:nodes` prop and stuck
// on the empty state. Modal-open flags use a `federation…` prefix to
// avoid colliding with the legacy AddDirectoryModal / EditListing keys.

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
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		summary() {
			const counts = { up: 0, degraded: 0, down: 0, unknown: 0 }
			for (const l of this.listings) {
				counts[this.statusFor(l)]++
			}
			return counts
		},
	},
	watch: {
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		modalState(next, prev) {
			const dirModals = ['federationAddDirectory', 'federationEditListing', 'federationDeleteListing']
			if (dirModals.includes(prev) && next !== prev) {
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
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
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
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		statusFor(listing) {
			if (listing.available === false) return 'down'
			if (typeof listing.statusCode === 'number') {
				if (listing.statusCode >= 200 && listing.statusCode < 300) return 'up'
				if (listing.statusCode >= 500) return 'down'
				return 'degraded'
			}
			return 'unknown'
		},
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		statusLabelFor(listing) {
			const map = {
				up: t('opencatalogi', 'available'),
				degraded: t('opencatalogi', 'degraded'),
				down: t('opencatalogi', 'unreachable'),
				unknown: t('opencatalogi', 'unknown'),
			}
			return map[this.statusFor(listing)]
		},
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		messageFor(listing) {
			if (listing.available === false) return t('opencatalogi', 'Peer is unreachable')
			if (typeof listing.statusCode === 'number' && (listing.statusCode < 200 || listing.statusCode >= 300)) {
				return t('opencatalogi', 'HTTP status:') + ' ' + listing.statusCode
			}
			return ''
		},
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		openAdd() {
			navigationStore.setModal('federationAddDirectory')
		},
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		openEdit(listing) {
			this.editingListing = listing
			navigationStore.setModal('federationEditListing')
		},
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
		openDelete(listing) {
			this.deletingListing = listing
			navigationStore.setModal('federationDeleteListing')
		},
		// @spec exclude review-driven UI hardening for WOO-511 federation directory affordances
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
				<span class="hidden-visually">{{ statusLabelFor(listing) }}</span>
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
						:menu-name="t('opencatalogi', 'Actions')"
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
/* WCAG 4.1.2 — pair `aria-hidden` dots with sr-only status text (F12). */
.hidden-visually {
	position: absolute !important;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
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
