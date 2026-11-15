<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @visual exclude Renders only a publication read from a second, federated instance; its baseline needs two instances, which the e2e spec of federation-open-remote-publication task 2.1 starts (left open in that change).
-->
<script>
import {
	CnDetailCard,
	CnDetailGrid,
	CnEmptyContent,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import { sourceUrl } from '../../services/federationResultTarget.js'

/**
 * A publication another organisation's instance holds, read inside this app:
 * its metadata from `GET /api/federation/publications/{id}` and its
 * attachments from `/attachments`, both answered from the source at the time
 * of the request. The attachments keep the source's download URLs, and
 * "Open at the source" links to the source's own history-mode page.
 *
 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-a-federated-result-opens-inside-the-app-req-for-001
 */
export default {
	name: 'FederatedPublicationView',
	components: {
		CnDetailCard,
		CnDetailGrid,
		CnEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			publication: null,
			loadFailed: false,
			attachments: [],
			attachmentsFailed: false,
		}
	},

	computed: {
		/**
		 * The publication id from the route.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-a-federated-result-opens-inside-the-app-req-for-001
		 */
		publicationId() {
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * The catalogue slug on the source: the search result's, else a slug
		 * the publication itself names. Never a guess from a title.
		 *
		 * @return {string|null}
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-page-links-correctly-to-the-source-req-for-003
		 */
		catalogSlug() {
			const fromRoute = this.$route?.query?.catalog
			if (typeof fromRoute === 'string' && fromRoute !== '') {
				return fromRoute
			}
			const catalog = this.publication?.['@self']?.catalog
			if (typeof catalog === 'string' && catalog !== '') {
				return catalog
			}
			return catalog?.slug || null
		},

		/**
		 * The title, or a placeholder when the publication has none.
		 *
		 * @return {string} The title.
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-a-federated-result-opens-inside-the-app-req-for-001
		 */
		title() {
			return (
				this.publication?.title
				|| this.publication?.['@self']?.name
				|| t('opencatalogi', 'Untitled publication')
			)
		},

		/**
		 * The metadata rows: organisation, publication date and source instance.
		 *
		 * @return {Array<{label: string, value: string}>}
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-a-federated-result-opens-inside-the-app-req-for-001
		 */
		details() {
			const publication = this.publication || {}
			return [
				{ label: t('opencatalogi', 'Organization'), value: displayValue(publication.organization ?? publication['@self']?.organisation) },
				{ label: t('opencatalogi', 'Publication date'), value: displayDate(publication.published ?? publication['@self']?.published) },
				{ label: t('opencatalogi', 'Source'), value: displayValue(publication['@self']?.directory) },
			].filter((row) => row.value !== '')
		},

		/**
		 * @return {string|null} The source's own page for this publication.
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-page-links-correctly-to-the-source-req-for-003
		 */
		sourceLink() {
			return this.publication ? sourceUrl(this.publication, this.catalogSlug) : null
		},
	},

	watch: {
		/**
		 * Read the other publication when the route moves to another id.
		 *
		 * @return {void}
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-a-federated-result-opens-inside-the-app-req-for-001
		 */
		publicationId() {
			this.load()
		},
	},

	created() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the publication, then its attachments; either failing says so
		 * on the page instead of showing an empty one.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-attachments-of-a-remote-publication-are-listed-from-the-source-req-for-002
		 */
		async load() {
			this.loading = true
			this.loadFailed = false
			this.attachmentsFailed = false
			this.publication = null
			this.attachments = []
			const base = generateUrl('/apps/opencatalogi/api/federation/publications/{id}', { id: this.publicationId })
			try {
				const response = await axios.get(base)
				this.publication = response.data
			} catch {
				this.loadFailed = true
				this.loading = false
				return
			}
			try {
				const response = await axios.get(`${base}/attachments`)
				this.attachments = Array.isArray(response.data?.results) ? response.data.results : []
			} catch {
				this.attachmentsFailed = true
			}
			this.loading = false
		},

		/**
		 * @param {object} attachment An attachment.
		 * @return {string} Its name.
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-attachments-of-a-remote-publication-are-listed-from-the-source-req-for-002
		 */
		attachmentName(attachment) {
			return attachment?.title || attachment?.name || attachment?.filename || t('opencatalogi', 'Download')
		},

		/**
		 * @param {object} attachment An attachment.
		 * @return {string|null} The source's download URL.
		 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-attachments-of-a-remote-publication-are-listed-from-the-source-req-for-002
		 */
		attachmentUrl(attachment) {
			return attachment?.downloadUrl || attachment?.accessUrl || null
		},
	},
}

/**
 * A value as a reader sees it: a string, an object's name or title, or ''.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
function displayValue(value) {
	if (value === null || value === undefined) {
		return ''
	}
	if (typeof value === 'object') {
		return String(value.name || value.title || '')
	}
	return String(value)
}

/**
 * A date as a reader sees it, or '' when there is none.
 *
 * @param {unknown} value An ISO date.
 * @return {string}
 */
function displayDate(value) {
	if (!value) {
		return ''
	}
	const date = new Date(String(value))
	return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString()
}
</script>

<template>
	<div class="federated-publication">
		<NcLoadingIcon v-if="loading" :size="44" />
		<CnEmptyContent
			v-else-if="loadFailed"
			:name="t('opencatalogi', 'Publication')"
			:description="
				t(
					'opencatalogi',
					'The external catalogue could not be reached, so nothing is known about it right now.',
				)
			"
			:error="true" />
		<template v-else>
			<header class="federated-publication__header">
				<h2>{{ title }}</h2>
				<p v-if="publication.summary" class="federated-publication__summary">
					{{ publication.summary }}
				</p>
				<a
					v-if="sourceLink"
					class="federated-publication__source"
					:href="sourceLink"
					rel="noopener noreferrer">
					{{ t('opencatalogi', 'Open at the source') }}
				</a>
			</header>

			<CnDetailCard :title="t('opencatalogi', 'Publication')">
				<CnDetailGrid :items="details" />
				<p v-if="publication.description" class="federated-publication__description">
					{{ publication.description }}
				</p>
			</CnDetailCard>

			<CnDetailCard :title="t('opencatalogi', 'Attachments')">
				<p v-if="attachmentsFailed">
					{{
						t(
							'opencatalogi',
							'The external catalogue could not be reached, so nothing is known about it right now.',
						)
					}}
				</p>
				<p v-else-if="attachments.length === 0">
					{{ t('opencatalogi', 'No files attached') }}
				</p>
				<ul v-else class="federated-publication__attachments">
					<li v-for="(attachment, index) in attachments" :key="attachment.id || index">
						<a
							v-if="attachmentUrl(attachment)"
							:href="attachmentUrl(attachment)"
							rel="noopener noreferrer">
							{{ attachmentName(attachment) }}
						</a>
						<span v-else>{{ attachmentName(attachment) }}</span>
					</li>
				</ul>
			</CnDetailCard>
		</template>
	</div>
</template>

<style scoped>
.federated-publication {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 960px;
	padding: 16px 16px 16px 56px;
}

.federated-publication__summary,
.federated-publication__description {
	color: var(--color-text-maxcontrast);
}

.federated-publication__source {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.federated-publication__attachments {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 0;
	list-style: none;
}

.federated-publication__attachments a {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
