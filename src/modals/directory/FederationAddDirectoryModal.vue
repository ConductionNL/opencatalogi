<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// FederationAddDirectoryModal — modal-isolated "Add directory" surface for
// the manifest-v2 Directory page (src/views/directory/FederationDirectory.vue).
//
// The pre-existing src/modals/directory/AddDirectoryModal.vue is coupled to
// a legacy src/modals/Modals.vue mount site that no longer exists in the
// manifest-v2 shell (App.vue mounts CnAppRoot, not Modals.vue). Rather than
// reintroduce Modals.vue as a global side-mount — a bigger surface change —
// this file provides the minimal isolated modal needed by the new page.
// Isolation lives under src/modals/ so hydra-gate-modal-isolation is
// satisfied (no inline <NcModal> in the parent view).
//
// References:
//   - WOO-510 — parent fix.

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcModal, NcButton, NcTextField, NcNoteCard } from '@nextcloud/vue'
import { navigationStore } from '../../store/store.js'

export default {
	name: 'FederationAddDirectoryModal',
	components: { NcModal, NcButton, NcTextField, NcNoteCard },
	data() {
		return {
			url: '',
			submitting: false,
			error: null,
			result: null,
		}
	},
	computed: {
		/**
		 * Whether the add-directory modal is the active navigation-store modal.
		 *
		 * Manifest-v2 uses a `federation…` prefix so the flag never collides
		 * with the legacy `AddDirectoryModal` (which keys on `'addDirectory'`)
		 * if both shells ever coexist.
		 *
		 * @return {boolean} True when this modal should render.
		 * @spec openspec/specs/retrofit-2026-05-26-directory-federation/spec.md#requirement-directory-listing-modals-req-dir-003
		 */
		isOpen() {
			return navigationStore.modal === 'federationAddDirectory'
		},
	},
	watch: {
		/**
		 * Reset the form whenever the modal transitions to open, so a previous
		 * submission's URL, error or result never leaks into the next use.
		 *
		 * @param {boolean} open The new open state.
		 * @return {void}
		 * @spec openspec/specs/retrofit-2026-05-26-directory-federation/spec.md#requirement-directory-listing-modals-req-dir-003
		 */
		isOpen(open) {
			if (open) {
				this.reset()
			}
		},
	},
	methods: {
		t,
		/**
		 * Clear the form back to its pristine state each time the modal opens.
		 *
		 * @return {void}
		 * @spec openspec/specs/retrofit-2026-05-26-directory-federation/spec.md#requirement-directory-listing-modals-req-dir-003
		 */
		reset() {
			this.url = ''
			this.submitting = false
			this.error = null
			this.result = null
		},
		/**
		 * Close the modal on completion or cancel.
		 *
		 * @return {void}
		 * @spec openspec/specs/retrofit-2026-05-26-directory-federation/spec.md#requirement-directory-listing-modals-req-dir-003
		 */
		close() {
			navigationStore.setModal(null)
		},
		/**
		 * POST the peer directory URL to /api/listings/add.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/dashboard/spec.md#requirement-add-a-new-listing-from-a-url-admin-only-dir-005
		 */
		async submit() {
			const trimmed = this.url.trim()
			if (!trimmed) {
				return
			}
			this.submitting = true
			this.error = null
			try {
				const endpoint = generateUrl('/apps/opencatalogi/api/listings/add')
				const res = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
					body: JSON.stringify({ url: trimmed }),
				})
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(body.message || `HTTP ${res.status}`)
				}
				this.result = body
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
	<NcModal v-if="isOpen"
		label-id="federationAddDirectoryModal"
		@close="close">
		<div class="federation-add-directory-modal">
			<h2>{{ t('opencatalogi', 'Add directory') }}</h2>
			<p class="federation-add-directory-modal__hint">
				{{ t('opencatalogi', 'Enter the peer directory URL to sync catalogs and publications from that instance.') }}
			</p>

			<NcTextField
				v-if="!result"
				v-model="url"
				:label="t('opencatalogi', 'Directory URL')"
				placeholder="https://peer.example.org/index.php/apps/opencatalogi/api/directory" />

			<NcNoteCard v-if="error" type="error">
				<p><strong>{{ t('opencatalogi', 'Failed to add directory') }}</strong></p>
				<p>{{ error }}</p>
			</NcNoteCard>

			<NcNoteCard v-if="result" type="success">
				<p><strong>{{ t('opencatalogi', 'Directory added') }}</strong></p>
				<p>
					{{ t('opencatalogi', 'New listings:') }} {{ result.listings_created }} ·
					{{ t('opencatalogi', 'Updated listings:') }} {{ result.listings_updated }} ·
					{{ t('opencatalogi', 'Failed listings:') }} {{ result.listings_failed }}
				</p>
			</NcNoteCard>

			<div class="federation-add-directory-modal__actions">
				<NcButton @click="close">
					{{ result ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
				</NcButton>
				<NcButton v-if="!result"
					variant="primary"
					:disabled="!url.trim() || submitting"
					@click="submit">
					{{ submitting ? t('opencatalogi', 'Adding…') : t('opencatalogi', 'Add') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<style scoped>
.federation-add-directory-modal {
	padding: 24px;
	min-width: 480px;
}
.federation-add-directory-modal__hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 16px;
}
.federation-add-directory-modal__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 24px;
}
</style>
