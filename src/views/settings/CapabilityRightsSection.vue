<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="capability-rights">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="32" />
		<table v-else-if="rows.length > 0" class="capability-rights__table" data-testid="capability-rights">
			<caption class="hidden-visually">
				{{ t('opencatalogi', 'Rights and the groups granted each') }}
			</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('opencatalogi', 'Right') }}
					</th>
					<th scope="col">
						{{ t('opencatalogi', 'Groups') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.verb" :data-verb="row.verb">
					<th scope="row" class="capability-rights__right">
						<label :for="`capability-right-${row.verb}`">{{ row.label }}</label>
					</th>
					<td>
						<NcSelect
							:modelValue="row.selected"
							:options="options"
							:inputId="`capability-right-${row.verb}`"
							:labelOutside="true"
							:multiple="true"
							:keepOpen="true"
							:placeholder="t('opencatalogi', 'Administrators only')"
							:disabled="saving === row.verb"
							label="label"
							trackBy="id"
							@update:modelValue="save(row, $event)" />
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { grantPayload, groupOptions, rightsRefusal, rightsRows } from './capabilityRights.js'

/**
 * The rights table (REQ-WAB-004): the eight Woo rights and the groups
 * granted each, changed by an administrator. A change is saved at once; an
 * administrator always holds every right, so an empty row means
 * "administrators only".
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */
export default {
	name: 'CapabilityRightsSection',

	components: { NcLoadingIcon, NcNoteCard, NcSelect },

	data() {
		return {
			loading: true,
			saving: '',
			error: '',
			options: [],
			rows: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the groups and the rights.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [groups, rights] = await Promise.all([
					axios.get(generateOcsUrl('cloud/groups/details'), { params: { limit: 500 } }),
					axios.get(generateUrl('/apps/opencatalogi/api/settings/capability-rights')),
				])
				this.options = groupOptions(groups.data)
				this.rows = rightsRows(rights.data, this.options, this.t)
			} catch (error) {
				this.error = rightsRefusal(error, this.t)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Grant one right to exactly the picked groups.
		 *
		 * @param {object} row The right's row.
		 * @param {Array<{id: string, label: string}>} selected The picked groups.
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
		 */
		async save(row, selected) {
			const before = row.selected
			row.selected = selected || []
			this.saving = row.verb
			this.error = ''
			try {
				const { data } = await axios.put(
					generateUrl(`/apps/opencatalogi/api/settings/capability-rights/${row.verb}`),
					grantPayload(row.selected),
				)
				this.rows = rightsRows(data, this.options, this.t)
			} catch (error) {
				row.selected = before
				this.error = rightsRefusal(error, this.t)
			} finally {
				this.saving = ''
			}
		},
	},
}
</script>

<style scoped>
.capability-rights__table {
	width: 100%;
	max-width: 900px;
	border-collapse: collapse;
}

.capability-rights__table th,
.capability-rights__table td {
	padding: 8px;
	text-align: start;
	vertical-align: middle;
	border-bottom: 1px solid var(--color-border);
}

.capability-rights__right {
	font-weight: normal;
	width: 40%;
}
</style>
