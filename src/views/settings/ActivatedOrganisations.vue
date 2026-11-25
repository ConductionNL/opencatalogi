<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="activated-organisations" data-testid="activated-organisations">
		<h3 class="activated-organisations__heading">
			{{ t('opencatalogi', 'Organisations you publish for') }}
		</h3>
		<p class="activated-organisations__hint">
			{{
				t(
					'opencatalogi',
					'Activate the organisations this installation publishes for, out of the national TOOI register. Once one is activated, the sitemap names only activated organisations as publisher or responsible organisation.',
				)
			}}
		</p>
		<form class="activated-organisations__search" role="search" @submit.prevent="search">
			<NcTextField
				v-model="query"
				:label="t('opencatalogi', 'Search by name or code')"
				:disabled="busy"
				data-testid="activated-organisations-query" />
			<NcButton
				type="submit"
				:disabled="busy"
				data-testid="activated-organisations-search">
				{{ t('opencatalogi', 'Search') }}
			</NcButton>
		</form>
		<NcNoteCard
			v-if="error"
			type="error"
			data-testid="activated-organisations-error">
			{{ error }}
		</NcNoteCard>
		<p v-if="!error && results.length === 0" class="activated-organisations__empty" data-testid="activated-organisations-empty">
			{{ emptyText }}
		</p>
		<ul v-else class="activated-organisations__list" aria-live="polite">
			<li
				v-for="organisation in results"
				:key="organisation.uri"
				:data-testid="'activated-organisation-' + organisation.notation">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="organisation.active"
					:disabled="busy"
					@update:modelValue="setActive(organisation, $event)">
					{{ organisation.label }}
					<span class="activated-organisations__code">{{ organisation.notation }}</span>
				</NcCheckboxRadioSwitch>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextField } from '@nextcloud/vue'

/**
 * The URL that searches the TOOI organisation registers.
 */
export const SEARCH_URL = '/apps/opencatalogi/api/woo/organisations'

/**
 * The URL that activates or deactivates one organisation.
 */
export const ACTIVATION_URL = '/apps/opencatalogi/api/woo/organisations/activation'

/**
 * The administrator activates the organisations the installation publishes for (REQ-WVL-005).
 *
 * Without a query the section lists the activated organisations; a query
 * searches the TOOI registers by name or code, activated ones first.
 * Activating links the OpenRegister organisation with that TOOI identifier,
 * or creates it; deactivating keeps the organisation and its publications.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
export default {
	name: 'ActivatedOrganisations',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			query: '',
			searched: '',
			results: [],
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * What an empty list says: nothing activated yet, or nothing found.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
		 * @return {string} The text.
		 */
		emptyText() {
			return this.searched === ''
				? t('opencatalogi', 'No organisation is activated yet, so every organisation is named in the sitemap.')
				: t('opencatalogi', 'No organisation in the TOOI register matches this search.')
		},
	},

	mounted() {
		this.search()
	},

	methods: {
		/**
		 * Search the registers for the query; an empty query lists the activated organisations.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
		 * @return {Promise<void>}
		 */
		async search() {
			this.error = ''
			this.busy = true
			const query = this.query.trim()
			try {
				const { data } = await axios.get(generateUrl(SEARCH_URL), { params: { search: query } })
				this.results = data?.results || []
				this.searched = query
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Activate or deactivate one organisation; on a refusal its switch stays as it was.
		 *
		 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
		 * @param {object} organisation The organisation: `{uri, label}`.
		 * @param {boolean} active Whether it is to be active.
		 * @return {Promise<void>}
		 */
		async setActive(organisation, active) {
			this.error = ''
			this.busy = true
			try {
				await axios.put(generateUrl(ACTIVATION_URL), { entry: organisation.uri, active: active === true })
				this.results = this.results.map((row) => (row.uri === organisation.uri ? { ...row, active: active === true } : row))
			} catch (err) {
				this.error = organisation.label + ': ' + (err.response?.data?.error || err.message)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.activated-organisations__heading {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.activated-organisations__hint,
.activated-organisations__empty,
.activated-organisations__code {
	color: var(--color-text-maxcontrast);
}

.activated-organisations__search {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 600px;
}

.activated-organisations__list {
	list-style: none;
	padding: 0;
}

.activated-organisations__code {
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
}
</style>
