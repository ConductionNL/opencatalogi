<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publiccode-harvest" data-testid="publiccode-harvest">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading && !status" :size="32" />

		<template v-else-if="status">
			<NcNoteCard
				v-if="!status.integriq"
				type="info"
				data-testid="publiccode-harvest-needs-integriq">
				{{
					t(
						'opencatalogi',
						'The GitHub harvest needs integriq. Install and enable integriq, then come back here.',
					)
				}}
			</NcNoteCard>

			<template v-else>
				<ul class="publiccode-harvest__checks">
					<li data-testid="publiccode-harvest-source">
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
							data-testid="publiccode-harvest-source-link">
							{{ t('opencatalogi', 'Open the source in integriq') }}
						</a>
					</li>
					<li data-testid="publiccode-harvest-shards">
						<CheckCircle
							v-if="shardsReady"
							:size="20"
							fillColor="var(--color-success)" />
						<CloseCircle
							v-else
							:size="20"
							fillColor="var(--color-error)" />
						<span>
							{{
								t(
									'opencatalogi',
									'{present} of {expected} search shards are set up',
									{
										present: status.shards.present,
										expected: status.shards.expected,
									},
								)
							}}
						</span>
					</li>
					<li data-testid="publiccode-harvest-flow">
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
					<li data-testid="publiccode-harvest-last-run">
						<InformationOutline :size="20" />
						<span>{{ lastRunLabel }}</span>
					</li>
				</ul>

				<p class="publiccode-harvest__hint">
					{{
						t(
							'opencatalogi',
							'OpenCatalogi never sees the GitHub token. integriq keeps it with the source.',
						)
					}}
				</p>

				<div class="publiccode-harvest__actions">
					<NcButton
						variant="secondary"
						:disabled="busy"
						data-testid="publiccode-harvest-setup"
						@click="act('setup')">
						{{ t('opencatalogi', 'Set up') }}
					</NcButton>
					<NcButton
						v-if="!status.flow.enabled"
						variant="primary"
						:disabled="busy || !status.flow.imported"
						data-testid="publiccode-harvest-enable"
						@click="act('enable')">
						{{ t('opencatalogi', 'Switch on') }}
					</NcButton>
					<NcButton
						v-else
						variant="secondary"
						:disabled="busy"
						data-testid="publiccode-harvest-disable"
						@click="act('disable')">
						{{ t('opencatalogi', 'Switch off') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="busy || !status.flow.enabled || !shardsReady"
						data-testid="publiccode-harvest-run"
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

const BASE = '/apps/opencatalogi/api/settings/publiccode-harvest'

/**
 * The GitHub harvest in the admin settings (REQ-PGH-006).
 *
 * Shows whether integriq, its GitHub source, the search shards and the flow
 * are ready, and sets the harvest up, switches it and runs it. It reads no
 * token: integriq keeps that with the source.
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
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

	data() {
		return {
			status: null,
			loading: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		sourceReady() {
			return Boolean(
				this.status?.source?.exists && this.status?.source?.enabled,
			)
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		shardsReady() {
			const shards = this.status?.shards
			return Boolean(
				shards && shards.expected > 0 && shards.present === shards.expected,
			)
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		sourceUrl() {
			return generateUrl('/apps/integriq/sources/{uuid}', {
				uuid: this.status.source.uuid,
			})
		},

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		sourceLabel() {
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

		/** @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi */
		lastRunLabel() {
			const run = this.status.lastRun
			if (!run) {
				return this.t('opencatalogi', 'The harvest has not run yet.')
			}
			const started = run.started ? new Date(run.started).toLocaleString() : ''
			if (run.status === 'suspended' && run.resumeAt) {
				return this.t(
					'opencatalogi',
					'Last run started {started}. It waits for the GitHub rate limit and goes on at {resume}.',
					{ started, resume: new Date(run.resumeAt).toLocaleString() },
				)
			}
			// The end node stops the run on purpose once every shard has written,
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
		 * Read the harvest status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(BASE))
				this.status = data
				this.error = ''
			} catch {
				this.error = this.t(
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
		 * @param {string} action setup, enable, disable or run.
		 * @param {object} payload The request body.
		 * @return {Promise<void>}
		 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
		 */
		async act(action, payload = {}) {
			this.busy = true
			try {
				await axios.post(generateUrl(BASE + '/' + action), payload)
				this.error = ''
			} catch (e) {
				this.error =
					e?.response?.data?.error
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
