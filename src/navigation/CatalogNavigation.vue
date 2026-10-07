<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnAppNav
		:manifest="navManifest"
		:permissions="permissions"
		:isOwner="isOwner"
		:isAdmin="isAdmin"
		:appId="appId" />
</template>

<script>
import { CnAppNav } from '@conduction/nextcloud-vue'
import { objectStore } from '../store/store.js'
import { withCatalogEntries } from './catalogMenuEntries.js'

/**
 * CatalogNavigation — CnAppRoot's `#menu` override: the library's CnAppNav
 * with one entry per catalog the user can access, between Dashboard and
 * Search. Takes CnAppRoot's menu slot bindings and forwards them unchanged.
 */
export default {
	name: 'CatalogNavigation',
	components: { CnAppNav },

	props: {
		/** The menu manifest from CnAppRoot's `#menu` slot. */
		manifest: { type: Object, default: null },
		/** Permissions used to filter menu entries. */
		permissions: { type: Array, default: () => [] },
		/** Whether the user owns this app. */
		isOwner: { type: Boolean, default: false },
		/** Whether the user administers the instance. */
		isAdmin: { type: Boolean, default: false },
		/** The app id, used for the Admin-settings target. */
		appId: { type: String, default: null },
	},

	computed: {
		/** @spec openspec/specs/retrofit-2026-05-26-app-shell-settings/spec.md#requirement-catalog-driven-main-menu-req-shell-004 */
		navManifest() {
			return withCatalogEntries(this.manifest, objectStore.menuCatalogs)
		},
	},
}
</script>
