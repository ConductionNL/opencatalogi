<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="woo-request-handover" data-testid="woo-request-handover">
		<h3 class="woo-request-handover__heading">
			{{ t('opencatalogi', 'Woo requests') }}
		</h3>
		<NcNoteCard v-if="missing" type="warning" data-testid="dossiq-missing">
			<p>
				{{
					t(
						'opencatalogi',
						'Woo requests are handled by dossiq, which is not installed.',
					)
				}}
			</p>
			<a :href="appStoreUrl" target="_blank" rel="noopener noreferrer">
				{{ t('opencatalogi', 'Install dossiq from the app store') }}
			</a>
		</NcNoteCard>
		<p
			v-for="(line, index) in lines"
			:key="index"
			class="woo-request-handover__line"
			:data-testid="'woo-request-handover-line-' + index">
			{{ line }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'
import {
	DOSSIQ_APP_STORE_URL,
	dossiqMissing,
	wooRequestHandoverLines,
} from './recordHandover.js'

/**
 * The Woo requests in the Woo section of the admin settings: without dossiq a
 * notice with the way to install it (REQ-WHD-005), with dossiq what the
 * hand-over of the stored requests left (REQ-WHD-003).
 */
export default {
	name: 'RecordHandover',

	components: { NcNoteCard },

	data() {
		return {
			state: null,
			appStoreUrl: DOSSIQ_APP_STORE_URL,
		}
	},

	computed: {
		/** @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005 */
		missing() {
			return dossiqMissing(this.state)
		},

		/** @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/tasks.md#task-3.2 */
		lines() {
			return wooRequestHandoverLines(this.state, this.t, (value) =>
				new Date(value).toLocaleString(),
			)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the hand-over state the readiness endpoint answers with.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/tasks.md#task-3.2
		 */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/opencatalogi/api/woo/readiness'),
				)
				this.state = data?.wooRequests ?? null
			} catch {
				this.state = null
			}
		},
	},
}
</script>

<style scoped>
.woo-request-handover__heading {
	margin-top: 2rem;
}

.woo-request-handover__line {
	margin: 0.5rem 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
