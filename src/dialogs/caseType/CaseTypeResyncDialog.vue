<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('opencatalogi', 'Resync with the case type')"
		size="large"
		@update:open="onOpenChange">
		<div class="case-type-resync">
			<p v-if="entryTitle" class="case-type-resync__subject">
				{{ entryTitle }}
			</p>
			<div v-if="state === 'loading'" class="case-type-resync__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<NcNoteCard v-else-if="state === 'no-case-type'" type="info">
				{{
					t(
						'opencatalogi',
						'This entry starts no case type, so there is nothing to resync.',
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-else-if="state === 'error'" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-else-if="state === 'done'" type="success">
				{{ t('opencatalogi', 'The case type was resynced.') }}
			</NcNoteCard>
			<template v-else-if="state === 'ready'">
				<p>
					{{ summary }}
				</p>
				<table v-if="changes.length > 0" class="case-type-resync__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('opencatalogi', 'Property') }}
							</th>
							<th scope="col">
								{{ t('opencatalogi', 'In the catalogue') }}
							</th>
							<th scope="col">
								{{ t('opencatalogi', 'In the case type') }}
							</th>
							<th scope="col">
								{{ t('opencatalogi', 'Choice') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="change in changes"
							:key="change.property"
							:data-property="change.property">
							<th scope="row">
								{{ change.property }}
							</th>
							<td>{{ displayValue(change.localValue) }}</td>
							<td>{{ displayValue(change.sourceValue) }}</td>
							<td class="case-type-resync__choice">
								<NcCheckboxRadioSwitch
									v-model="choices[change.property]"
									type="radio"
									value="keep"
									:name="`resync-${change.property}`">
									{{ t('opencatalogi', 'Keep') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									v-model="choices[change.property]"
									type="radio"
									value="take"
									:name="`resync-${change.property}`">
									{{ t('opencatalogi', 'Take') }}
								</NcCheckboxRadioSwitch>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>
		<template #actions>
			<NcButton @click="close">
				{{
					state === 'done'
						? t('opencatalogi', 'Close')
						: t('opencatalogi', 'Cancel')
				}}
			</NcButton>
			<NcButton
				v-if="state === 'ready' && changes.length > 0"
				variant="primary"
				:disabled="applying"
				@click="apply">
				<template v-if="applying" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Resync') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { navigationStore } from '../../store/store.js'

/**
 * CaseTypeResyncDialog: resynchronise the case type a catalogue entry starts
 * with its source, property by property.
 *
 * Reads `navigationStore.dialogProperties` `{ caseType, entryTitle }`, where
 * `caseType` is the identifier in the entry's form binding. Finds the case
 * type definition with that identifier, shows what
 * `GET /api/case-types/{id}/resync` reports as changed, and posts the
 * properties the administrator takes as `accepted`.
 *
 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
 */
export default {
	name: 'CaseTypeResyncDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			/** @type {'loading'|'no-case-type'|'error'|'ready'|'done'} */
			state: 'loading',
			error: '',
			definition: null,
			changes: [],
			/** Per property: 'keep' or 'take'. */
			choices: {},
			applying: false,
		}
	},

	computed: {
		/** @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002 */
		properties() {
			return navigationStore.dialogProperties || {}
		},

		/** @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002 */
		entryTitle() {
			return this.properties.entryTitle || ''
		},

		/** @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002 */
		caseTypeName() {
			return (
				this.definition?.title
				|| this.definition?.identifier
				|| this.properties.caseType
				|| ''
			)
		},

		/** @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002 */
		summary() {
			const vars = { name: this.caseTypeName, count: this.changes.length }
			if (this.changes.length === 0) {
				return t(
					'opencatalogi',
					'The case type {name} matches its source.',
					vars,
				)
			}
			return this.changes.length === 1
				? t(
						'opencatalogi',
						'The case type {name} differs from its source in 1 property.',
						vars,
					)
				: t(
						'opencatalogi',
						'The case type {name} differs from its source in {count} properties.',
						vars,
					)
		},

		/** @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002 */
		accepted() {
			return this.changes
				.map((change) => change.property)
				.filter((property) => this.choices[property] === 'take')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Find the case type definition and read what a resync would change.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
		 */
		async load() {
			const identifier = String(this.properties.caseType || '').trim()
			if (identifier === '') {
				this.state = 'no-case-type'
				return
			}
			try {
				const { data: found } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}',
						{
							register: loadState(
								'opencatalogi',
								'service_catalogue_register',
								'',
							),
							schema: loadState(
								'opencatalogi',
								'case_type_definition_schema',
								'',
							),
						},
					),
					{ params: { identifier, _limit: 1 } },
				)
				this.definition = found?.results?.[0] ?? null
				if (!this.definition?.id) {
					this.state = 'no-case-type'
					return
				}
				const { data: diff } = await axios.get(this.resyncUrl())
				this.changes = Array.isArray(diff?.changes) ? diff.changes : []
				this.choices = Object.fromEntries(
					this.changes.map((change) => [
						change.property,
						change.locallyChanged ? 'keep' : 'take',
					]),
				)
				this.state = 'ready'
			} catch (error) {
				this.fail(error)
			}
		},

		/**
		 * Post the taken properties.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
		 */
		async apply() {
			this.applying = true
			try {
				await axios.post(this.resyncUrl(), { accepted: this.accepted })
				this.state = 'done'
			} catch (error) {
				this.fail(error)
			} finally {
				this.applying = false
			}
		},

		/**
		 * @return {string} The resync endpoint of the found definition.
		 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
		 */
		resyncUrl() {
			return generateUrl('/apps/opencatalogi/api/case-types/{id}/resync', {
				id: this.definition.id,
			})
		},

		/**
		 * Show the server's own message, else a general one.
		 *
		 * @param {Error} error The failure.
		 * @return {void}
		 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
		 */
		fail(error) {
			this.error =
				error?.response?.data?.message
				|| t('opencatalogi', 'The case type could not be resynced.')
			this.state = 'error'
		},

		/**
		 * @param {unknown} value A property value.
		 * @return {string} The value as text.
		 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
		 */
		displayValue(value) {
			if (value === null || value === undefined || value === '') {
				return t('opencatalogi', 'Not set')
			}
			return typeof value === 'object' ? JSON.stringify(value) : String(value)
		},

		/**
		 * @param {boolean} open Whether the dialog stays open.
		 * @return {void}
		 * @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002
		 */
		onOpenChange(open) {
			if (!open) {
				this.close()
			}
		},

		/** @spec openspec/specs/service-catalogue-admin-screens/spec.md#requirement-a-case-type-is-resynchronised-property-by-property-sca-002 */
		close() {
			navigationStore.setDialog(false)
		},
	},
}
</script>

<style scoped>
.case-type-resync__subject {
	font-weight: 600;
	margin-block-end: calc(var(--default-grid-baseline) * 2);
}

.case-type-resync__loading {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
}

.case-type-resync__table {
	width: 100%;
	border-collapse: collapse;
}

.case-type-resync__table th,
.case-type-resync__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-block-end: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.case-type-resync__choice {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
