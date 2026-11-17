<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
  The staging banner (instance-staging-mode REQ-STG-001): shown on every page
  of the app while the instance is in staging, so nobody mistakes a rehearsal
  for the real thing. The server provides the mode with the page; anything but
  production reads as staging. Rendered once by App.vue.
-->
<template>
	<NcNoteCard
		v-if="staging"
		type="warning"
		class="staging-banner"
		data-testid="staging-banner">
		{{ t('opencatalogi', 'This instance is in staging: nothing leaves it. Hand-overs are kept as dry runs.') }}
	</NcNoteCard>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'

/**
 * Whether the page was served by an instance in staging.
 *
 * @return {boolean}
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
export function isStaging() {
	return loadState('opencatalogi', 'instance_mode', 'production') !== 'production'
}

export default {
	name: 'StagingBanner',
	components: { NcNoteCard },

	data() {
		return { staging: isStaging() }
	},

	methods: { t },
}
</script>

<style scoped>
.staging-banner {
	margin: calc(var(--default-grid-baseline) * 2);
}
</style>
