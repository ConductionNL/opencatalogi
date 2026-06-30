<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// FederationDirectory — data layer for the Directory page. The manifest's
// previous `component: "CnFederationStatus"` mounted the bare presentational
// widget with no `:nodes` prop, so it always rendered the empty-state
// regardless of /api/listings content. This wrapper fetches the peer
// listings and maps them to the shape CnFederationStatus expects.
//
// References:
//   - WOO-493 manual walkthrough (2026-06-30) — surfaced the missing binding.
//   - lib/Controller/ListingsController.php — backing /api/listings endpoint.
//
// i18n: strings on this page are English-only pending a translation pass
// across the required European locales — tracked as the parent issue's l10n
// follow-up.

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { CnFederationStatus } from '@conduction/nextcloud-vue'
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue'

export default {
	name: 'FederationDirectory',
	components: { CnFederationStatus, NcButton, NcModal, NcTextField },
	data() {
		return {
			listings: [],
			loading: false,
			error: null,
			addOpen: false,
			addUrl: '',
			adding: false,
			addError: null,
		}
	},
	computed: {
		nodes() {
			return this.listings.map((l) => ({
				id: l.id || l['@self']?.id,
				name: l.title || l.name || l.directory || 'Unnamed peer',
				url: l.directory || l.search || null,
				status: this.statusFor(l),
				message: this.messageFor(l),
				lastChecked: l.lastSyncAt || l.updated || null,
			}))
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
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
		statusFor(listing) {
			if (listing.available === false) return 'down'
			if (typeof listing.statusCode === 'number') {
				if (listing.statusCode >= 200 && listing.statusCode < 300) return 'up'
				if (listing.statusCode >= 500) return 'down'
				return 'degraded'
			}
			return 'unknown'
		},
		messageFor(listing) {
			if (listing.available === false) return 'Peer is unreachable'
			if (typeof listing.statusCode === 'number' && (listing.statusCode < 200 || listing.statusCode >= 300)) {
				return `HTTP status: ${listing.statusCode}`
			}
			return ''
		},
		openAdd() {
			this.addUrl = ''
			this.addError = null
			this.addOpen = true
		},
		async submitAdd() {
			this.adding = true
			this.addError = null
			try {
				const url = generateUrl('/apps/opencatalogi/api/listings/add')
				const res = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
					body: JSON.stringify({ url: this.addUrl.trim() }),
				})
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(body.message || `HTTP ${res.status}`)
				}
				this.addOpen = false
				await this.load()
			} catch (e) {
				this.addError = e.message || String(e)
			} finally {
				this.adding = false
			}
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
				Add directory
			</NcButton>
		</header>

		<p v-if="loading" class="federation-directory__hint">
			Loading federated peers…
		</p>
		<p v-else-if="error" class="federation-directory__error">
			Failed to load directory: {{ error }}
		</p>

		<CnFederationStatus
			:nodes="nodes"
			empty-label="No federation peers registered. Use 'Add directory' to connect to a peer instance." />

		<NcModal v-if="addOpen" @close="addOpen = false">
			<div class="federation-directory__modal">
				<h3>Add directory</h3>
				<p class="federation-directory__hint">
					Enter the peer's directory URL (e.g. http://nc-fed-2/index.php/apps/opencatalogi/api/directory).
				</p>
				<NcTextField
					:value.sync="addUrl"
					:label="t('opencatalogi', 'Directory URL')"
					placeholder="https://peer.example.org/index.php/apps/opencatalogi/api/directory" />
				<p v-if="addError" class="federation-directory__error">
					{{ addError }}
				</p>
				<div class="federation-directory__actions">
					<NcButton @click="addOpen = false">
						{{ t('opencatalogi', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="!addUrl || adding" @click="submitAdd">
						{{ adding ? 'Adding…' : t('opencatalogi', 'Add') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
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
.federation-directory__hint {
	color: var(--color-text-maxcontrast);
}
.federation-directory__error {
	color: var(--color-error);
}
.federation-directory__modal {
	padding: 24px;
	min-width: 480px;
}
.federation-directory__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 16px;
}
</style>
