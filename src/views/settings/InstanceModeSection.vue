<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
/**
 * Instance mode in the admin settings: production or staging, changed only
 * after a confirmation, and the OpenRegister webhooks and integriq
 * synchronisations OpenCatalogi does not stop (instance-staging-mode REQ-STG-001).
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { onMounted, ref } from 'vue'
import InstanceModeConfirmDialog from '../../dialogs/settings/InstanceModeConfirmDialog.vue'
import { fetchInstanceMode, saveInstanceMode } from './instanceModeRequests.js'

defineOptions({ name: 'InstanceModeSection' })

const mode = ref('production')
const webhooks = ref([])
const synchronisations = ref([])
const chosen = ref(null)
const saving = ref(false)
const error = ref('')

onMounted(async () => {
	try {
		const state = await fetchInstanceMode()
		mode.value = state.mode
		webhooks.value = state.webhooks
		synchronisations.value = state.synchronisations
	} catch {
		error.value = t('opencatalogi', 'The instance mode could not be read.')
	}
})

/**
 * Ask for confirmation of a newly chosen mode.
 *
 * @param {string} value The chosen mode.
 */
function choose(value) {
	if (value !== mode.value) {
		chosen.value = value
	}
}

/** Save the confirmed mode. */
async function confirm() {
	saving.value = true
	error.value = ''
	try {
		const answer = await saveInstanceMode(chosen.value)
		mode.value = answer.mode
	} catch {
		error.value = t('opencatalogi', 'The instance mode could not be saved.')
	} finally {
		saving.value = false
		chosen.value = null
	}
}
</script>

<template>
	<div class="instance-mode">
		<NcCheckboxRadioSwitch
			:modelValue="mode"
			value="production"
			name="instance-mode"
			type="radio"
			@update:modelValue="choose">
			{{ t('opencatalogi', 'Production') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			:modelValue="mode"
			value="staging"
			name="instance-mode"
			type="radio"
			@update:modelValue="choose">
			{{ t('opencatalogi', 'Staging') }}
		</NcCheckboxRadioSwitch>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<h3>{{ t('opencatalogi', 'OpenCatalogi does not stop these') }}</h3>
		<p>{{ t('opencatalogi', 'Enabled OpenRegister webhooks') }}</p>
		<ul>
			<li v-for="name in webhooks" :key="'w-' + name">
				{{ name }}
			</li>
			<li v-if="webhooks.length === 0">
				{{ t('opencatalogi', 'None') }}
			</li>
		</ul>
		<p>{{ t('opencatalogi', 'Enabled integriq synchronisations') }}</p>
		<ul>
			<li v-for="name in synchronisations" :key="'s-' + name">
				{{ name }}
			</li>
			<li v-if="synchronisations.length === 0">
				{{ t('opencatalogi', 'None') }}
			</li>
		</ul>
		<InstanceModeConfirmDialog
			:open="chosen !== null"
			:mode="chosen || mode"
			:saving="saving"
			@confirm="confirm"
			@cancel="chosen = null" />
	</div>
</template>

<style scoped>
.instance-mode ul {
	list-style: disc;
	padding-inline-start: calc(var(--default-grid-baseline) * 6);
}
</style>
