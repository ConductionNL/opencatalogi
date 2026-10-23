<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<fieldset class="catalog-staff-access" data-testid="catalog-staff-access">
		<legend class="catalog-staff-access__legend">
			{{ t('opencatalogi', 'Staff access') }}
		</legend>
		<NcCheckboxRadioSwitch
			:modelValue="value.restricted"
			type="switch"
			:disabled="disabled"
			data-testid="catalog-staff-access-restricted"
			@update:modelValue="update({ restricted: $event })">
			{{
				t(
					'opencatalogi',
					"Only these groups can see and edit this catalogue's publications",
				)
			}}
		</NcCheckboxRadioSwitch>
		<template v-if="value.restricted">
			<NcSelect
				:modelValue="selected(value.readGroups)"
				:options="groupOptions"
				:inputLabel="
					t('opencatalogi', 'Who can see unpublished publications')
				"
				:disabled="disabled"
				multiple
				@update:modelValue="update({ readGroups: ids($event) })" />
			<NcSelect
				:modelValue="selected(value.editGroups)"
				:options="groupOptions"
				:inputLabel="t('opencatalogi', 'Who can edit')"
				:disabled="disabled"
				multiple
				@update:modelValue="update({ editGroups: ids($event) })" />
			<p class="catalog-staff-access__hint">
				{{
					t(
						'opencatalogi',
						'Administrators always keep access. Another catalogue over the same publications shares this access.',
					)
				}}
			</p>
		</template>
	</fieldset>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import { getNextcloudGroups } from '../services/nextcloudGroups.js'

/**
 * CatalogStaffAccessFields: whether a catalogue restricts who sees and edits
 * its publications, and the groups that may. Edits a `staffAccess` value
 * `{ restricted, readGroups, editGroups }`; OpenRegister enforces it through
 * the rules opencatalogi writes when the catalogue is saved.
 *
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-the-administrator-sets-staff-access-on-the-catalogue-edit-page-req-csa-003
 */
export default {
	name: 'CatalogStaffAccessFields',
	components: { NcCheckboxRadioSwitch, NcSelect },

	props: {
		/** The catalogue's staff access; absent is unrestricted. */
		modelValue: { type: Object, default: null },
		/** Whether the fields are read-only. */
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return { groupOptions: [] }
	},

	computed: {
		/** @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-the-administrator-sets-staff-access-on-the-catalogue-edit-page-req-csa-003 */
		value() {
			return {
				restricted: this.modelValue?.restricted === true,
				readGroups: [...(this.modelValue?.readGroups || [])],
				editGroups: [...(this.modelValue?.editGroups || [])],
			}
		},
	},

	async mounted() {
		const groups = await getNextcloudGroups()
		this.groupOptions = groups.map((group) => ({
			id: group.value,
			label: group.label,
		}))
	},

	methods: {
		t,

		/**
		 * Emit the staff access with a change applied.
		 *
		 * @param {object} change The changed keys.
		 * @return {void}
		 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-the-administrator-sets-staff-access-on-the-catalogue-edit-page-req-csa-003
		 */
		update(change) {
			this.$emit('update:modelValue', { ...this.value, ...change })
		},

		/**
		 * @param {Array<string>} groups Group ids.
		 * @return {Array<{id: string, label: string}>} The options for them.
		 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-the-administrator-sets-staff-access-on-the-catalogue-edit-page-req-csa-003
		 */
		selected(groups) {
			return groups.map(
				(id) =>
					this.groupOptions.find((option) => option.id === id) || {
						id,
						label: id,
					},
			)
		},

		/**
		 * @param {Array<{id: string}>} options The chosen options.
		 * @return {Array<string>} Their group ids.
		 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-the-administrator-sets-staff-access-on-the-catalogue-edit-page-req-csa-003
		 */
		ids(options) {
			return (options || []).map((option) => option.id)
		},
	},
}
</script>

<style scoped>
.catalog-staff-access {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block-start: calc(var(--default-grid-baseline) * 4);
	border: none;
	padding: 0;
}

.catalog-staff-access__legend {
	font-weight: 600;
}

.catalog-staff-access__hint {
	color: var(--color-text-maxcontrast);
}
</style>
