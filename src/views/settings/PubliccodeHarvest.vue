<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publiccode-harvest" :data-testid="prefix">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading && !status" :size="32" />

		<template v-else-if="status">
			<NcNoteCard
				v-if="!status.integriq"
				type="info"
				:data-testid="prefix + '-needs-integriq'">
				{{ needsIntegriqLabel }}
			</NcNoteCard>

			<template v-else>
				<ul class="publiccode-harvest__checks">
					<li :data-testid="prefix + '-source'">
						<CheckCircle
							v-if="sourceReady"
							:size="20"
							fillColor="var(--color-success)" />
						<CloseCircle
							v-else
							:size="20"
							fillColor="var(--color-error)" />
						<span>{{ sourceLabel }}</span>
						<a
							v-if="status.source && status.source.uuid"
							:href="sourceUrl"
							:data-testid="prefix + '-source-link'">
							{{ t('opencatalogi', 'Open the source in integriq') }}
						</a>
					</li>
					<li
						v-for="(other, slug) in otherSources"
						:key="slug"
						:data-testid="prefix + '-file-source-' + slug">
						<CheckCircle
							v-if="other.exists && other.enabled"
							:size="20"
							fillColor="var(--color-success)" />
						<CloseCircle
							v-else
							:size="20"
							fillColor="var(--color-error)" />
						<span>{{ otherSourceLabel(slug, other) }}</span>
					</li>
					<li :data-testid="prefix + '-' + countKey">
						<CheckCircle
							v-if="setupReady"
							:size="20"
							fillColor="var(--color-success)" />
						<CloseCircle
							v-else
							:size="20"
							fillColor="var(--color-error)" />
						<span>{{ setupLabel }}</span>
					</li>
					<li :data-testid="prefix + '-flow'">
						<CheckCircle
							v-if="status.flow.enabled"
							:size="20"
							fillColor="var(--color-success)" />
						<MinusCircle
							v-else
							:size="20"
							fillColor="var(--color-text-maxcontrast)" />
						<span>{{ flowLabel }}</span>
					</li>
					<li :data-testid="prefix + '-last-run'">
						<InformationOutline :size="20" />
						<span>{{ lastRunLabel }}</span>
					</li>
					<li
						v-if="status.lastRun && status.lastRun.failedSteps > 0"
						:data-testid="prefix + '-failed-steps'">
						<InformationOutline :size="20" />
						<span>{{ failedStepsLabel }}</span>
					</li>
				</ul>

				<p class="publiccode-harvest__hint">
					{{ hintLabel }}
					<a
						v-if="keyRequestUrl"
						:href="keyRequestUrl"
						target="_blank"
						rel="noopener noreferrer"
						:data-testid="prefix + '-key-request-link'">
						{{ t('opencatalogi', 'Request an API key at developer.overheid.nl') }}
					</a>
				</p>

				<div class="publiccode-harvest__actions">
					<NcButton
						variant="secondary"
						:disabled="busy"
						:data-testid="prefix + '-setup'"
						@click="act('setup')">
						{{ t('opencatalogi', 'Set up') }}
					</NcButton>
					<NcButton
						v-if="!status.flow.enabled"
						variant="primary"
						:disabled="busy || !status.flow.imported"
						:data-testid="prefix + '-enable'"
						@click="act('enable')">
						{{ t('opencatalogi', 'Switch on') }}
					</NcButton>
					<NcButton
						v-else
						variant="secondary"
						:disabled="busy"
						:data-testid="prefix + '-disable'"
						@click="act('disable')">
						{{ t('opencatalogi', 'Switch off') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="busy || !status.flow.enabled || !setupReady"
						:data-testid="prefix + '-run'"
						@click="act('run')">
						{{ t('opencatalogi', 'Run now') }}
					</NcButton>
				</div>
			</template>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import MinusCircle from 'vue-material-design-icons/MinusCircle.vue'

/**
 * Per harvest: the endpoint base, the test-id prefix and the status key the
 * synchronizations are counted under. Every user-facing string stays a literal
 * t() call in a computed property, so the l10n scanner sees it.
 */
const HARVESTS = {
	github: {
		base: '/apps/opencatalogi/api/settings/publiccode-harvest',
		prefix: 'publiccode-harvest',
		countKey: 'shards',
	},
	'developer-overheid': {
		base: '/apps/opencatalogi/api/settings/developer-overheid-harvest',
		prefix: 'developer-overheid-harvest',
		countKey: 'synchronizations',
	},
}

/**
 * A publiccode harvest in the admin settings: the GitHub harvest
 * (REQ-PGH-006) by default, or the developer.overheid.nl harvest
 * (REQ-PDH-008) with `kind="developer-overheid"`.
 *
 * Shows whether integriq, the harvest's source, its synchronizations and the
 * flow are ready, and sets the harvest up, switches it and runs it. It reads
 * no token or key: integriq keeps that with the source.
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */
export default {
	name: 'PubliccodeHarvest',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CheckCircle,
		CloseCircle,
		InformationOutline,
		MinusCircle,
	},

	props: {
		/**
		 * Which harvest the section drives: `github` or `developer-overheid`.
		 */
		kind: {
			type: String,
			default: 'github',
			validator: (value) => Object.keys(HARVESTS).includes(value),
		},
	},

	data() {
		return {
			status: null,
			loading: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		isDeveloperOverheid() {
			return this.kind === 'developer-overheid'
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		base() {
			return HARVESTS[this.kind].base
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		prefix() {
			return HARVESTS[this.kind].prefix
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		countKey() {
			return HARVESTS[this.kind].countKey
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		needsIntegriqLabel() {
			if (this.isDeveloperOverheid) {
				return this.t(
					'opencatalogi',
					'The developer.overheid.nl harvest needs integriq. Install and enable integriq, then come back here.',
				)
			}
			return this.t(
				'opencatalogi',
				'The GitHub harvest needs integriq. Install and enable integriq, then come back here.',
			)
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		sourceReady() {
			return Boolean(
				this.status?.source?.exists && this.status?.source?.enabled,
			)
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		otherSources() {
			return this.status?.sources ?? {}
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		setupReady() {
			const counted = this.status?.[this.countKey]
			return Boolean(
				counted && counted.expected > 0 && counted.present === counted.expected,
			)
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		setupLabel() {
			const counted = this.status[this.countKey]
			const counts = {
				present: counted.present,
				expected: counted.expected,
			}
			if (this.isDeveloperOverheid) {
				return this.t(
					'opencatalogi',
					'{present} of {expected} synchronizations are set up',
					counts,
				)
			}
			return this.t(
				'opencatalogi',
				'{present} of {expected} search shards are set up',
				counts,
			)
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		sourceUrl() {
			return generateUrl('/apps/integriq/sources/{uuid}', {
				uuid: this.status.source.uuid,
			})
		},

		/**
		 * Where an administrator requests their own read-only key. Shown only
		 * for the developer.overheid.nl harvest; OpenCatalogi never sees the key.
		 *
		 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-009-the-api-key-never-touches-opencatalogi
		 */
		keyRequestUrl() {
			if (this.isDeveloperOverheid) {
				return 'https://apis.developer.overheid.nl/apis/key-aanvragen'
			}
			return ''
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		sourceLabel() {
			if (this.isDeveloperOverheid) {
				return this.developerOverheidSourceLabel
			}
			const source = this.status.source
			if (!source || !source.exists) {
				return this.t(
					'opencatalogi',
					'integriq has no GitHub source {slug}. Update integriq.',
					{ slug: source?.slug ?? 'github-api' },
				)
			}
			if (!source.enabled) {
				return this.t(
					'opencatalogi',
					'The GitHub source {slug} is switched off. Add the token and switch it on in integriq.',
					{ slug: source.slug },
				)
			}
			return this.t(
				'opencatalogi',
				'The GitHub source {slug} is switched on.',
				{
					slug: source.slug,
				},
			)
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		developerOverheidSourceLabel() {
			const source = this.status.source
			if (!source || !source.exists) {
				return this.t(
					'opencatalogi',
					'integriq has no developer.overheid.nl source {slug}. Update integriq.',
					{ slug: source?.slug ?? 'developer-overheid-oss' },
				)
			}
			if (!source.enabled) {
				return this.t(
					'opencatalogi',
					'The developer.overheid.nl source {slug} is switched off. Enter your API key on it and switch it on in integriq.',
					{ slug: source.slug },
				)
			}
			return this.t(
				'opencatalogi',
				'The developer.overheid.nl source {slug} is switched on.',
				{ slug: source.slug },
			)
		},

		/** @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-009-the-api-key-never-touches-opencatalogi */
		hintLabel() {
			if (this.isDeveloperOverheid) {
				return this.t(
					'opencatalogi',
					'OpenCatalogi never sees your developer.overheid.nl API key. You store it as credential {credential}, and integriq adds it to every request to the source.',
					{ credential: 'developer-overheid-oss' },
				)
			}
			return this.t(
				'opencatalogi',
				'OpenCatalogi never sees the GitHub token. integriq keeps it with the source.',
			)
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		flowLabel() {
			const flow = this.status.flow
			if (!flow.imported) {
				return this.t(
					'opencatalogi',
					'The harvest flow is not imported yet. Reimport the configuration at the top of this page.',
				)
			}
			if (!flow.enabled) {
				return this.t('opencatalogi', 'The harvest is off.')
			}
			return this.t(
				'opencatalogi',
				'The harvest is on. It runs as {user} on schedule {cron}.',
				{ user: flow.runAs ?? '', cron: flow.cron ?? '' },
			)
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-004-a-file-that-cannot-be-read-is-skipped-not-written */
		failedStepsLabel() {
			const count = this.status.lastRun?.failedSteps ?? 0
			if (this.isDeveloperOverheid) {
				return this.t(
					'opencatalogi',
					'{count} step(s) of the last run failed and were skipped, so some repositories were not written. The run log in OpenRegister names them.',
					{ count },
				)
			}
			return this.t(
				'opencatalogi',
				'{count} step(s) of the last run failed and were skipped, so their shards did not finish. The run log in OpenRegister names them.',
				{ count },
			)
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		lastRunLabel() {
			const run = this.status.lastRun
			if (!run) {
				return this.t('opencatalogi', 'The harvest has not run yet.')
			}
			const started = run.started ? new Date(run.started).toLocaleString() : ''
			if (run.status === 'suspended' && run.resumeAt) {
				const resume = new Date(run.resumeAt).toLocaleString()
				if (this.isDeveloperOverheid) {
					return this.t(
						'opencatalogi',
						'Last run started {started}. It waits and goes on at {resume}.',
						{ started, resume },
					)
				}
				return this.t(
					'opencatalogi',
					'Last run started {started}. It waits for the GitHub rate limit and goes on at {resume}.',
					{ started, resume },
				)
			}
			// The end node stops the run on purpose once every branch has written,
			// so a clean stop without an error is a finished harvest.
			const finished =
				run.status === 'completed'
				|| (run.status === 'stopped' && !run.error)
			if (run.status === 'failed' || (run.status === 'stopped' && !finished)) {
				return this.t(
					'opencatalogi',
					'Last run started {started} and stopped: {error}',
					{ started, error: run.error ?? '' },
				)
			}
			if (finished) {
				return this.t(
					'opencatalogi',
					'Last run started {started} and finished.',
					{
						started,
					},
				)
			}
			return this.t(
				'opencatalogi',
				'Last run started {started} and is still running.',
				{
					started,
				},
			)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The label of one raw-file source.
		 *
		 * @param {string} slug The source slug.
		 * @param {{exists: boolean, enabled: boolean}} other Its status.
		 * @return {string}
		 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
		 */
		otherSourceLabel(slug, other) {
			if (!other.exists) {
				return this.t(
					'opencatalogi',
					'integriq has no file source {slug}. Update integriq.',
					{ slug },
				)
			}
			if (!other.enabled) {
				return this.t(
					'opencatalogi',
					'The file source {slug} is switched off. Switch it on in integriq.',
					{ slug },
				)
			}
			return this.t(
				'opencatalogi',
				'The file source {slug} is switched on.',
				{ slug },
			)
		},

		/**
		 * Read the harvest status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(this.base))
				this.status = data
				this.error = ''
			} catch {
				this.error = this.isDeveloperOverheid
					? this.t(
						'opencatalogi',
						'The developer.overheid.nl harvest status could not be loaded.',
					)
					: this.t(
						'opencatalogi',
						'The GitHub harvest status could not be loaded.',
					)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run one action, then read the status again.
		 *
		 * A refusal carries a localised `message` (and an `error` slug) on the
		 * developer.overheid.nl endpoints; the GitHub endpoints answer the
		 * reason in `error`.
		 *
		 * @param {string} action setup, enable, disable or run.
		 * @param {object} payload The request body.
		 * @return {Promise<void>}
		 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
		 */
		async act(action, payload = {}) {
			this.busy = true
			try {
				await axios.post(generateUrl(this.base + '/' + action), payload)
				this.error = ''
			} catch (e) {
				this.error =
					e?.response?.data?.message
					?? e?.response?.data?.error
					?? this.t('opencatalogi', 'That did not work. Try again.')
			} finally {
				this.busy = false
			}
			await this.load()
		},
	},
}
</script>

<style scoped>
.publiccode-harvest__checks {
	list-style: none;
	padding: 0;
	margin: 0 0 12px;
}

.publiccode-harvest__checks li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.publiccode-harvest__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.publiccode-harvest__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
