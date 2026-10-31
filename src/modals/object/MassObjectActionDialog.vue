<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The dialog shell shared by the Mass*Objects modals: the step content, the
  result cards and the cancel and confirm buttons.
-->
<template>
	<NcDialog
		:name="title"
		size="normal"
		class="mass-action-dialog"
		@update:open="onOpenChange">
		<div v-if="success === null">
			<slot />
		</div>
		<slot name="notes" />

		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null
						? t('opencatalogi', 'Cancel')
						: t('opencatalogi', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="confirmDisabled"
				:variant="confirmVariant"
				@click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<slot v-else name="icon" />
				</template>
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'MassObjectActionDialog',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Cancel,
	},

	props: {
		title: { type: String, required: true },
		confirmLabel: { type: String, required: true },
		confirmVariant: { type: String, default: 'primary' },
		confirmDisabled: { type: Boolean, default: false },
		loading: { type: Boolean, default: false },
		success: { type: Boolean, default: null },
		error: { type: [Boolean, String], default: false },
		successMessage: { type: String, default: '' },
	},

	emits: ['close', 'confirm'],

	methods: {
		/**
		 * @param {boolean} isOpen Whether the dialog stays open.
		 * @spec openspec/specs/generic-object-modals/spec.md
		 */
		onOpenChange(isOpen) {
			if (!isOpen) {
				this.$emit('close')
			}
		},
	},
}
</script>
