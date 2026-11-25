<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcNoteCard
		v-if="!dossiqInstalled"
		type="info"
		data-testid="woo-requests-dossiq-missing">
		<p>{{ t('opencatalogi', 'Woo requests are handled by dossiq, which is not installed.') }}</p>
		<a :href="appStoreUrl" data-testid="woo-requests-dossiq-install">
			{{ t('opencatalogi', 'Install dossiq from the app store') }}
		</a>
	</NcNoteCard>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'

/**
 * The Woo settings notice that Woo requests are dossiq's (REQ-WHD-005):
 * shown only when dossiq is not enabled, with a link to the app store.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
 */
export default {
	name: 'WooRequestHandover',

	components: { NcNoteCard },

	data() {
		return {
			dossiqInstalled: dossiqEnabled(globalThis.OC),
			appStoreUrl: generateUrl('/settings/apps'),
		}
	},
}

/**
 * Whether dossiq is enabled: Nextcloud lists every app enabled for the
 * signed-in user in `OC.appswebroots` on each page it renders.
 *
 * @param {object|undefined} oc The page's `OC` global.
 * @return {boolean} True when dossiq is enabled.
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
 */
export function dossiqEnabled(oc) {
	return Boolean(oc?.appswebroots?.dossiq)
}
</script>
