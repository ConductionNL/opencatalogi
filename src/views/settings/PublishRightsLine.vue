<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publish-rights" data-testid="publish-rights">
		<h3 class="publish-rights__heading">
			{{ t('opencatalogi', 'Publish rights') }}
		</h3>
		<p
			v-for="(line, index) in lines"
			:key="index"
			class="publish-rights__line"
			:data-testid="'publish-rights-line-' + index">
			{{ line }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { publishRightsLines } from './publishRights.js'

/**
 * The publishers group in the Woo section of the admin settings (REQ-OPR-004):
 * its name, whether it exists and how many members it has, so an administrator
 * can see the publish right is set up.
 */
export default {
	name: 'PublishRightsLine',

	data() {
		return {
			status: null,
			loaded: false,
		}
	},

	computed: {
		/** @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004 */
		lines() {
			if (!this.loaded) {
				return []
			}
			return publishRightsLines(this.status, this.t)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the publishers group.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
		 */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/opencatalogi/api/settings/publish-rights'),
				)
				this.status = data
			} catch {
				this.status = null
			} finally {
				this.loaded = true
			}
		},
	},
}
</script>

<style scoped>
.publish-rights__heading {
	margin-top: 2rem;
}

.publish-rights__line {
	margin: 0.5rem 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
