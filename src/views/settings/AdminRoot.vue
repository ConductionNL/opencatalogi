<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<Settings />
</template>

<script>
/**
 * The root of the admin settings bundle (`settings.js`): it renders the
 * settings page and nothing else. It is mounted directly, never through a
 * router, so no app route can render it.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-11
 */
import { defineComponent } from 'vue'
import Settings from './Settings.vue'

export default defineComponent({
	name: 'AdminRoot',
	components: {
		Settings,
	},
})
</script>
