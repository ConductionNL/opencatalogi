<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  The instance banners (REQ-PCS-103). An administrator sets a body, a period,
  a severity and whether it can be dismissed; the server returns only the
  banners inside their period that this user has not dismissed, and remembers
  a dismissal per user. Rendered once by App.vue, on every page of the app.
-->
<template>
	<div
		v-if="banners.length > 0"
		class="instance-banners"
		data-testid="instance-banners">
		<div
			v-for="banner in banners"
			:key="banner.id"
			class="instance-banners__banner"
			:data-testid="'instance-banner-' + banner.id">
			<NcNoteCard
				:type="noteTypeFor(banner.severity)"
				class="instance-banners__card">
				<div class="instance-banners__body">
					<p class="instance-banners__text">
						{{ banner.body }}
					</p>
					<NcButton
						v-if="banner.dismissable"
						variant="tertiary"
						:aria-label="t('opencatalogi', 'Dismiss this announcement')"
						:disabled="dismissing === banner.id"
						@click="dismiss(banner)">
						{{ t('opencatalogi', 'Dismiss') }}
					</NcButton>
				</div>
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	dismissInstanceBanner,
	loadInstanceBanners,
	noteTypeFor,
} from '../services/instanceBanners.js'

/**
 * An app URL from a path under /apps/opencatalogi.
 *
 * @param {string} path The path, starting with a slash.
 * @return {string} The URL.
 */
const appUrl = (path) => generateUrl('/apps/opencatalogi' + path)

export default {
	name: 'InstanceBanners',
	components: { NcButton, NcNoteCard },

	data() {
		return {
			banners: [],
			dismissing: null,
		}
	},

	async mounted() {
		this.banners = await loadInstanceBanners(axios, appUrl)
	},

	methods: {
		t,
		noteTypeFor,

		/**
		 * Dismiss a banner for this user. It disappears at once; when the
		 * server refuses, it comes back so the user is not told something
		 * was remembered that was not.
		 *
		 * @param {object} banner The banner.
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
		 */
		async dismiss(banner) {
			this.dismissing = banner.id
			const before = this.banners
			this.banners = this.banners.filter((b) => b.id !== banner.id)
			try {
				await dismissInstanceBanner(axios, appUrl, banner.id)
			} catch {
				this.banners = before
			} finally {
				this.dismissing = null
			}
		},
	},
}
</script>

<style scoped>
.instance-banners {
	position: fixed;
	inset-block-end: 16px;
	inset-inline-end: 16px;
	z-index: 1000;
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: min(480px, calc(100vw - 32px));
}

.instance-banners__banner {
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	background-color: var(--color-main-background);
}

.instance-banners__banner .instance-banners__card {
	margin: 0;
}

.instance-banners__body {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.instance-banners__text {
	flex: 1 1 auto;
	margin: 0;
	white-space: pre-line;
	overflow-wrap: anywhere;
}
</style>
