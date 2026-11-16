<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - The redaction log of a partly public document: per redacted passage its
  - type, where it sits and the grounds (woo-review-surface, REQ-WRV-003).
  - Internal: shown to officers and reviewers, never on a public page.
  -->
<template>
	<div class="woo-redaction-log">
		<h4 class="woo-redaction-log__title">
			{{ t('opencatalogi', 'Redaction log') }}
		</h4>
		<p v-if="entries.length === 0" class="woo-redaction-log__empty">
			{{ t('opencatalogi', 'No redacted passages are logged. The document cannot be published like this.') }}
		</p>
		<table v-else class="woo-redaction-log__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('opencatalogi', 'Type') }}
					</th>
					<th scope="col">
						{{ t('opencatalogi', 'Position') }}
					</th>
					<th scope="col">
						{{ t('opencatalogi', 'Grounds') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(entry, index) in entries" :key="entry.relationId || index">
					<td>{{ entry.entityType }}</td>
					<td>{{ position(entry) }}</td>
					<td :class="{ 'woo-redaction-log__missing': grounds(entry) === '' }">
						{{ grounds(entry) || t('opencatalogi', 'No ground: blocks publishing') }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * The grounds of one log entry as text.
 *
 * @param {object} entry The log entry.
 * @return {string} The grounds, comma separated, or an empty string.
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-redaction-log-records-what-was-removed-and-on-which-ground-req-wrv-003
 */
export function groundsText(entry) {
	const grounds = Array.isArray(entry?.grounds) ? entry.grounds : []
	return grounds
		.map((ground) => (typeof ground === 'string' ? ground : ground?.code || ground?.article || ground?.label || ground?.name || ''))
		.filter(Boolean)
		.join(', ')
}

export default {
	name: 'RedactionLog',

	props: {
		/** The `redactionLog` of the assessment. */
		log: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return { t }
	},

	computed: {
		/** @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-redaction-log-records-what-was-removed-and-on-which-ground-req-wrv-003 */
		entries() {
			return Array.isArray(this.log) ? this.log : []
		},
	},

	methods: {
		/**
		 * The grounds of one entry.
		 *
		 * @param {object} entry The log entry.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-redaction-log-records-what-was-removed-and-on-which-ground-req-wrv-003
		 * @return {string} The grounds.
		 */
		grounds(entry) {
			return groundsText(entry)
		},

		/**
		 * Where the passage sits in the text.
		 *
		 * @param {object} entry The log entry.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-redaction-log-records-what-was-removed-and-on-which-ground-req-wrv-003
		 * @return {string} The character range.
		 */
		position(entry) {
			const start = entry?.positionStart
			const end = entry?.positionEnd
			if (start === undefined || start === null) {
				return ''
			}
			return `${start}–${end ?? start}`
		},
	},
}
</script>

<style scoped>
.woo-redaction-log__title {
	margin: 8px 0 4px;
}

.woo-redaction-log__empty,
.woo-redaction-log__missing {
	color: var(--color-text-error, var(--color-error-text));
}

.woo-redaction-log__table {
	border-collapse: collapse;
	width: 100%;
}

.woo-redaction-log__table th,
.woo-redaction-log__table td {
	border-bottom: 1px solid var(--color-border);
	padding: 4px 8px;
	text-align: start;
}
</style>
