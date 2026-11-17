<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
/**
 * Asks the administrator to confirm a change of instance mode before it is
 * saved, saying what the new mode does (instance-staging-mode REQ-STG-001).
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { computed } from 'vue'

defineOptions({ name: 'InstanceModeConfirmDialog' })

const props = defineProps({
	/** Whether the dialog is open. */
	open: { type: Boolean, default: false },
	/** The mode the administrator chose: production or staging. */
	mode: { type: String, required: true },
	/** Whether the change is being saved. */
	saving: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'cancel'])

const question = computed(() => (props.mode === 'staging'
	? t('opencatalogi', 'Put this instance in staging? Hand-overs are kept as dry runs and the public pages close to anonymous visitors until you switch back.')
	: t('opencatalogi', 'Put this instance back in production? Hand-overs leave again from now on. Dry runs are kept and are not sent.')))
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('opencatalogi', 'Change the instance mode')"
		@closing="emit('cancel')">
		<p>{{ question }}</p>
		<template #actions>
			<NcButton :disabled="saving" @click="emit('cancel')">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="emit('confirm')">
				{{ t('opencatalogi', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>
