<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="refusal-grounds-source" data-testid="refusal-grounds-source">
		<h3 class="refusal-grounds-source__heading">
			{{ t('opencatalogi', 'Refusal grounds') }}
		</h3>
		<NcNoteCard
			v-if="source === 'snapshot'"
			type="info"
			data-testid="refusal-grounds-snapshot">
			{{
				t(
					'opencatalogi',
					'The refusal grounds come from a fixed copy of the list maintained in dossiq. They can be cited on a redacted passage, but not edited here. Install dossiq to maintain them.',
				)
			}}
		</NcNoteCard>
		<p
			v-else-if="source === 'dossiq'"
			class="refusal-grounds-source__hint"
			data-testid="refusal-grounds-dossiq">
			{{ t('opencatalogi', 'The refusal grounds come from dossiq, where they are maintained.') }}
		</p>
		<NcNoteCard v-else-if="error" type="error" data-testid="refusal-grounds-error">
			{{ t('opencatalogi', 'The refusal grounds cannot be read: {error}', { error }) }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'

/**
 * Where the Woo refusal grounds come from, in the Woo settings (REQ-WVC-003).
 *
 * dossiq owns the grounds. Without dossiq OpenCatalogi reads a vendored copy
 * of dossiq's snapshot, which can be cited on a redacted passage and not
 * edited; this says so.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
 */
export default {
	name: 'RefusalGroundsSource',

	components: { NcNoteCard },

	data() {
		return {
			source: null,
			error: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the grounds and keep where they came from.
		 *
		 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
		 * @return {Promise<void>}
		 */
		async load() {
			this.error = null
			try {
				const { data } = await axios.get(generateUrl('/apps/opencatalogi/api/woo/weigeringsgronden'))
				this.source = sourceOf(data?.results || [])
			} catch (err) {
				this.source = null
				this.error = err.response?.data?.error || err.message
			}
		},
	},
}

/**
 * The source of a list of grounds: `snapshot` when any row is from the
 * vendored copy, `dossiq` when every row is dossiq's, else null.
 *
 * @param {Array<object>} rows The grounds.
 * @return {string|null} The source.
 */
export function sourceOf(rows) {
	if (rows.length === 0) {
		return null
	}
	if (rows.some((row) => row?.source === 'snapshot')) {
		return 'snapshot'
	}
	return rows.every((row) => row?.source === 'dossiq') ? 'dossiq' : null
}
</script>

<style scoped>
.refusal-grounds-source__heading {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.refusal-grounds-source__hint {
	color: var(--color-text-maxcontrast);
}
</style>
