<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="dcat-feed-validation" data-testid="dcat-feed-validation">
		<h3 class="dcat-feed-validation__heading">
			{{ t('opencatalogi', 'DCAT feed validation') }}
		</h3>
		<p class="dcat-feed-validation__hint">
			{{ t('opencatalogi', 'Check the DCAT feed of each catalogue against DCAT-AP-NL and against what data.overheid.nl accepts.') }}
		</p>
		<p v-if="loaded && catalogs.length === 0" class="dcat-feed-validation__hint" data-testid="dcat-validation-empty">
			{{ t('opencatalogi', 'No catalogue publishes a DCAT feed yet.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div
			v-for="catalog in catalogs"
			:key="catalog.slug"
			class="dcat-feed-validation__catalog"
			:data-testid="'dcat-validation-' + catalog.slug">
			<div class="dcat-feed-validation__row">
				<strong>{{ catalog.title || catalog.slug }}</strong>
				<NcButton
					variant="secondary"
					:disabled="busy[catalog.slug] === true"
					:data-testid="'dcat-validate-' + catalog.slug"
					@click="validate(catalog.slug)">
					{{ t('opencatalogi', 'Validate DCAT feed') }}
				</NcButton>
			</div>

			<template v-if="results[catalog.slug]">
				<NcNoteCard v-if="results[catalog.slug].error" type="error">
					{{ results[catalog.slug].error }}
				</NcNoteCard>
				<template v-else>
					<p class="dcat-feed-validation__hint">
						{{ profileLabel(results[catalog.slug].dcat) }}
					</p>
					<p class="dcat-feed-validation__hint" data-testid="dcat-validation-checked">
						{{ t('opencatalogi', 'Mandatory properties checked on every dataset: {properties}', { properties: (results[catalog.slug].dcat.checked || []).join(', ') }) }}
					</p>
					<NcNoteCard v-if="results[catalog.slug].dcat.valid" type="success" data-testid="dcat-validation-valid">
						{{ t('opencatalogi', 'Every dataset carries every checked property.') }}
					</NcNoteCard>
					<ul v-else class="dcat-feed-validation__list" data-testid="dcat-validation-violations">
						<li v-for="(violation, index) in results[catalog.slug].dcat.violations" :key="'dcat-' + index">
							{{ violationText(violation) }}
						</li>
					</ul>

					<NcNoteCard v-if="results[catalog.slug].donl.valid" type="success" data-testid="donl-validation-valid">
						{{ t('opencatalogi', 'data.overheid.nl would accept this feed.') }}
					</NcNoteCard>
					<template v-else>
						<p class="dcat-feed-validation__hint">
							{{ t('opencatalogi', 'data.overheid.nl would reject:') }}
						</p>
						<ul class="dcat-feed-validation__list" data-testid="donl-validation-violations">
							<li v-for="(violation, index) in results[catalog.slug].donl.violations" :key="'donl-' + index">
								{{ violationText(violation) }}
							</li>
						</ul>
					</template>
					<p v-if="results[catalog.slug].donl.sourceUrl" class="dcat-feed-validation__hint">
						{{ t('opencatalogi', 'Harvest source address: {url}', { url: results[catalog.slug].donl.sourceUrl }) }}
					</p>
				</template>
			</template>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

/**
 * Validate DCAT feed, per catalogue, in the admin DCAT settings (DCAT-010, DCAT-NPF-001).
 *
 * Shows the DCAT-AP-NL profile the verdict was measured against, every
 * mandatory property it checks, each dataset that misses one, and what
 * data.overheid.nl would reject. Advisory only: it never changes the feed.
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md
 */
export default {
	name: 'DcatFeedValidation',
	components: { NcButton, NcNoteCard },

	data() {
		return {
			catalogs: [],
			results: {},
			busy: {},
			loaded: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the catalogues that publish a DCAT feed.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('/apps/opencatalogi/api/catalogi'), { params: { _limit: 100 } })
				this.catalogs = (data?.results || [])
					.filter((catalog) => catalog?.hasDcat === true && catalog?.slug)
					.map((catalog) => ({ slug: catalog.slug, title: catalog.title || '' }))
			} catch {
				this.error = this.t('opencatalogi', 'The catalogues could not be loaded.')
			}
			this.loaded = true
		},

		/**
		 * Run both validations for one catalogue and keep the answers.
		 *
		 * @param {string} slug The catalogue slug.
		 * @return {Promise<void>}
		 */
		async validate(slug) {
			this.busy = { ...this.busy, [slug]: true }
			const base = '/apps/opencatalogi/api/catalogs/' + encodeURIComponent(slug) + '/dcat'
			try {
				const [dcat, donl] = await Promise.all([
					axios.get(generateUrl(base + '/validate')),
					axios.get(generateUrl(base + '/donl-validate')),
				])
				this.results = { ...this.results, [slug]: { dcat: dcat.data, donl: donl.data } }
			} catch (e) {
				const message = e?.response?.data?.error || this.t('opencatalogi', 'The DCAT feed could not be validated.')
				this.results = { ...this.results, [slug]: { error: message } }
			}
			this.busy = { ...this.busy, [slug]: false }
		},

		/**
		 * The profile the verdict was measured against.
		 *
		 * @param {object} report The DCAT validation report.
		 * @return {string} The label, or empty when the report names no profile.
		 */
		profileLabel(report) {
			const declared = report?.profile?.declared
			if (!declared) {
				return ''
			}
			return this.t('opencatalogi', 'Checked against DCAT-AP-NL {version}.', { version: declared })
		},

		/**
		 * One violation as a sentence.
		 *
		 * @param {object} violation A violation from either report.
		 * @return {string} The sentence.
		 */
		violationText(violation) {
			if (violation?.axis === 'profile-version') {
				return this.t('opencatalogi', 'The feed declares DCAT-AP-NL {declared}, while {published} is the published version.', {
					declared: violation.declared,
					published: violation.published,
				})
			}
			if (violation?.axis === 'dcat:theme') {
				return this.t('opencatalogi', '{dataset}: the theme is not a data-theme from the controlled list.', { dataset: violation.iri || '' })
			}
			return this.t('opencatalogi', '{dataset}: missing {properties}', {
				dataset: violation?.iri || '',
				properties: (violation?.missing || []).join(', '),
			})
		},
	},
}
</script>

<style scoped>
.dcat-feed-validation {
	margin-top: calc(var(--default-grid-baseline) * 6);
}

.dcat-feed-validation__hint {
	color: var(--color-text-maxcontrast);
}

.dcat-feed-validation__catalog {
	margin-top: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.dcat-feed-validation__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.dcat-feed-validation__list {
	list-style: disc;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}
</style>
