<script setup>
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One panel inside `<AppTabs>` — drop-in replacement for `bootstrap-vue`'s
 * `<BTab>`. See `AppTabs.vue` for why this pair exists locally and why it
 * belongs in `@conduction/nextcloud-vue`.
 *
 * Accepts EITHER a `title` prop or a `#title` slot, matching `<BTab>`; the
 * slot wins when both are given.
 */
import { getCurrentInstance, inject, onBeforeUnmount, onMounted, ref, useSlots } from 'vue'
import { TABS_CONTEXT } from './tabsContext.js'

const props = defineProps({
	/** Plain-text tab label. Ignored when a `#title` slot is supplied. */
	title: {
		type: String,
		default: '',
	},
	/** Marks this tab as the initially selected one (matches `<BTab active>`). */
	active: {
		type: Boolean,
		default: false,
	},
})

const slots = useSlots()
const panel = ref(null)
const uid = getCurrentInstance().uid

// A `<AppTab>` rendered outside an `<AppTabs>` would otherwise throw on every
// access. Fall back to a self-contained "always visible" context so a stray tab
// degrades to a plain visible block instead of crashing the whole page.
const tabs = inject(TABS_CONTEXT, null)

onMounted(() => {
	tabs?.register({
		uid,
		title: props.title,
		titleSlot: slots.title ?? null,
		el: panel,
		active: props.active,
	})
})

onBeforeUnmount(() => {
	tabs?.unregister(uid)
})
</script>

<template>
	<!--
		The panel stays MOUNTED and is hidden with `v-show`, exactly like
		`<BTab>` outside its opt-in `lazy` mode. Switching to `v-if` here would
		silently discard unsaved form state on every tab switch AND break the
		document-order sort in `<AppTabs>`.
	-->
	<div
		v-show="!tabs || tabs.isActive(uid)"
		ref="panel"
		class="app-tab"
		role="tabpanel">
		<slot />
	</div>
</template>
