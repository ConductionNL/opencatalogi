<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="woo-incidents" data-testid="woo-incidents">
		<h3 class="woo-incidents__heading">
			{{ t('opencatalogi', 'Pipeline failures') }}
		</h3>
		<p class="woo-incidents__hint">
			{{
				t(
					'opencatalogi',
					'A failed delivery, a refused sitemap or a gap in the sitemap is sent to the members of this group by notification and e-mail.',
				)
			}}
		</p>
		<NcNoteCard
			v-if="nobodyReceives"
			type="warning"
			data-testid="woo-incident-group-missing">
			<p>
				{{
					t(
						'opencatalogi',
						'Nobody receives pipeline failures. Set a group.',
					)
				}}
			</p>
		</NcNoteCard>
		<div class="woo-incidents__group">
			<NcTextField
				v-model="group"
				:label="t('opencatalogi', 'Group that receives pipeline failures')"
				data-testid="woo-incident-group" />
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="woo-incident-group-save"
				@click="save">
				{{ t('opencatalogi', 'Save') }}
			</NcButton>
		</div>
		<h4 class="woo-incidents__subheading">
			{{ t('opencatalogi', 'Open pipeline failures') }}
		</h4>
		<p
			v-if="state.error !== ''"
			class="woo-incidents__line"
			data-testid="woo-incidents-error">
			{{ t('opencatalogi', 'The open pipeline failures could not be read.') }}
		</p>
		<p
			v-else-if="state.incidents.length === 0"
			class="woo-incidents__line"
			data-testid="woo-incidents-none">
			{{ t('opencatalogi', 'No open pipeline failures.') }}
		</p>
		<ul v-else class="woo-incidents__list" data-testid="woo-incidents-list">
			<li
				v-for="incident in state.incidents"
				:key="incident.id"
				class="woo-incidents__line"
				data-testid="woo-incident">
				<strong>{{ incident.kind }}</strong> {{ incident.subject }}:
				{{ line(incident) }}
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import {
	incidentLine,
	incidentStateFrom,
	nobodyReceives,
	WOO_INCIDENT_GROUP_PATH,
	WOO_INCIDENTS_PATH,
} from './wooIncidents.js'

/**
 * The Woo pipeline failures in the Woo section of the admin settings: the group
 * that receives them and the ones still open (REQ-WNO-004).
 */
export default {
	name: 'WooIncidents',

	components: { NcButton, NcNoteCard, NcTextField },

	data() {
		return {
			state: incidentStateFrom(null),
			group: '',
			saving: false,
		}
	},

	computed: {
		/** @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004 */
		nobodyReceives() {
			return nobodyReceives(this.state)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the group and the open incidents.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
		 */
		async load() {
			try {
				const { data } = await axios.get(generateUrl(WOO_INCIDENTS_PATH))
				this.state = incidentStateFrom(data)
			} catch {
				this.state = incidentStateFrom({ error: 'unreadable' })
			}
			this.group = this.state.group
		},

		/**
		 * Save the group; the server assigns it to the incident schema's receiver role.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
		 */
		async save() {
			this.saving = true
			try {
				const { data } = await axios.put(
					generateUrl(WOO_INCIDENT_GROUP_PATH),
					{ group: this.group },
				)
				this.state = { ...this.state, group: String(data?.group ?? '') }
				this.group = this.state.group
				showSuccess(t('opencatalogi', 'The group is saved.'))
			} catch (error) {
				showError(
					error?.response?.data?.error
						?? t('opencatalogi', 'The group could not be saved.'),
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {object} incident The incident.
		 * @return {string} Its line.
		 */
		line(incident) {
			return incidentLine(incident, (value) =>
				new Date(value).toLocaleString(),
			)
		},
	},
}
</script>

<style scoped>
.woo-incidents__heading {
	margin-top: 2rem;
}

.woo-incidents__hint,
.woo-incidents__line {
	margin: 0.5rem 0 0;
	color: var(--color-text-maxcontrast);
}

.woo-incidents__group {
	display: flex;
	gap: 0.5rem;
	align-items: flex-end;
	max-width: 40rem;
	margin-top: 0.5rem;
}

.woo-incidents__subheading {
	margin-top: 1rem;
}

.woo-incidents__list {
	padding-left: 1rem;
	list-style: disc;
}
</style>
