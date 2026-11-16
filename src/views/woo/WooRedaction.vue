<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - The redaction page of a partly public Woo document
  - (woo-document-assessment-screen, REQ-WDA-003 and REQ-WDA-004).
  -
  - Shows the three steps, the redacted version and the original (switchable),
  - the findings OpenRegister holds for the redacted file with their grounds and
  - the kept and rejected counts, and lets a person mark the redaction verified.
  - Findings are decided in OpenRegister; each row links there. This page never
  - decides a finding itself.
  -->
<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import WooRedactionLog from '../../components/woo/WooRedactionLog.vue'
import {
	formatSize,
	redactionRows,
	redactionState,
	redactionSteps,
} from '../../services/wooHelpers.js'
import { objectStore } from '../../store/store.js'

/**
 * The object store type the page reads.
 */
const ASSESSMENT_TYPE = 'wooAssessment'

/**
 * An initial-state value, or an empty string when it is not there.
 *
 * @param {string} key The key.
 * @return {string} The value.
 */
function stateValue(key) {
	try {
		return String(loadState('opencatalogi', key, '') || '')
	} catch {
		return ''
	}
}

/**
 * Whether a reference is a Nextcloud file id.
 *
 * @param {string|number|null|undefined} reference The reference.
 * @return {boolean} True for a positive number.
 */
function isFileId(reference) {
	return /^\d+$/.test(String(reference ?? '')) && Number(reference) > 0
}

export default {
	name: 'WooRedaction',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		WooRedactionLog,
	},

	data() {
		return {
			t,
			formatSize,
			loading: false,
			error: null,
			assessment: null,
			relations: [],
			relationsError: null,
			version: 'redacted',
			verifying: false,
			verifyError: null,
		}
	},

	computed: {
		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003 */
		batchId() {
			return this.$route?.params?.id || ''
		},

		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003 */
		docId() {
			return this.$route?.params?.docId || ''
		},

		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003 */
		findings() {
			return redactionRows(this.relations)
		},

		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003 */
		steps() {
			const labels = {
				original: t('opencatalogi', 'Original kept, never published'),
				generated: t('opencatalogi', 'Redacted version generated'),
				verified: t('opencatalogi', 'Verified'),
			}
			return redactionSteps(this.assessment).map((step) => ({
				...step,
				label: labels[step.key],
			}))
		},

		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-person-marks-a-redaction-verified-before-it-can-be-published-req-wda-004 */
		state() {
			return redactionState(this.assessment)
		},

		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-person-marks-a-redaction-verified-before-it-can-be-published-req-wda-004 */
		canVerify() {
			return this.state === 'unchecked'
		},

		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003 */
		shownFileId() {
			const reference =
				this.version === 'original'
					? this.assessment?.documentReference
					: this.assessment?.anonymizedDocument
			return isFileId(reference) ? String(reference) : ''
		},
	},

	watch: {
		docId: {
			immediate: true,
			/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003 */
			handler() {
				if (this.docId) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Load the assessment through the object store, then the findings of its redacted file.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const register = stateValue('woo_register')
				const schema = stateValue('woo_assessment_schema')
				await objectStore.registerObjectType(
					ASSESSMENT_TYPE,
					schema,
					register,
				)
				this.assessment =
					(await objectStore.fetchObject(ASSESSMENT_TYPE, this.docId))
					|| null
			} catch (err) {
				this.error = err.response?.data?.error || err.message
				this.assessment = null
			} finally {
				this.loading = false
			}
			await this.loadRelations()
		},

		/**
		 * Read the redacted file's findings from OpenRegister.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
		 * @return {Promise<void>}
		 */
		async loadRelations() {
			this.relations = []
			this.relationsError = null
			const fileId = this.assessment?.anonymizedDocument
			if (!isFileId(fileId)) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/entity-relations'),
					{ params: { fileId } },
				)
				this.relations = data?.results || []
			} catch (err) {
				this.relationsError = err.response?.data?.error || err.message
			}
		},

		/**
		 * Mark the redaction verified by the signed-in officer.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-person-marks-a-redaction-verified-before-it-can-be-published-req-wda-004
		 * @return {Promise<void>}
		 */
		async verify() {
			this.verifying = true
			this.verifyError = null
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/opencatalogi/api/woo/batches/${this.batchId}/documents/${this.docId}/verify-redaction`,
					),
				)
				this.assessment = { ...this.assessment, ...data }
			} catch (err) {
				this.verifyError = err.response?.data?.error || err.message
			} finally {
				this.verifying = false
			}
		},

		/**
		 * A file's preview image.
		 *
		 * @param {string} fileId The Nextcloud file id.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
		 * @return {string} The URL.
		 */
		previewUrl(fileId) {
			return generateUrl(`/core/preview?fileId=${fileId}&x=1024&y=1024&a=1`)
		},

		/**
		 * A file in Nextcloud Files, where the viewer opens it.
		 *
		 * @param {string} fileId The Nextcloud file id.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
		 * @return {string} The URL.
		 */
		fileUrl(fileId) {
			return generateUrl(`/f/${fileId}`)
		},

		/**
		 * A finding in OpenRegister, where it is decided.
		 *
		 * @param {object} row The finding row.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
		 * @return {string} The URL, or an empty string without an entity.
		 */
		findingUrl(row) {
			return row.entityId
				? generateUrl(`/apps/openregister/entities/${row.entityId}`)
				: ''
		},

		/**
		 * A finding's state in words.
		 *
		 * @param {object} row The finding row.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
		 * @return {string} The label.
		 */
		findingState(row) {
			switch (row.state) {
				case 'kept':
					return t('opencatalogi', 'Kept')
				case 'rejected':
					return t('opencatalogi', 'Rejected')
				default:
					return t('opencatalogi', 'Decision not visible to you')
			}
		},

		/**
		 * Back to the batch page.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-partly-public-document-has-a-redaction-page-req-wda-003
		 * @return {object} The route.
		 */
		batchRoute() {
			return { name: 'WooBatchDetail', params: { id: this.batchId } }
		},
	},
}
</script>

<template>
	<div class="woo-redaction">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent
			v-else-if="!assessment"
			:name="error || t('opencatalogi', 'Document not found')" />
		<div v-else>
			<nav
				class="woo-redaction__breadcrumb"
				:aria-label="t('opencatalogi', 'Breadcrumb')">
				<router-link :to="{ name: 'WooBatches' }">
					{{ t('opencatalogi', 'WOO batches') }}
				</router-link>
				<span aria-hidden="true"> / </span>
				<router-link :to="batchRoute()">
					{{ assessment.caseReference || batchId }}
				</router-link>
				<span aria-hidden="true"> / </span>
				<span aria-current="page">{{ assessment.fileName || docId }}</span>
			</nav>

			<h2>{{ assessment.fileName || docId }}</h2>
			<p class="woo-redaction__chips">
				<span class="woo-redaction__chip">{{
					t('opencatalogi', 'Partly public')
				}}</span>
				<span
					v-if="state !== 'checked'"
					class="woo-redaction__chip woo-redaction__chip--warning">
					{{ t('opencatalogi', 'Redaction not verified') }}
				</span>
			</p>
			<p class="woo-redaction__meta">
				{{
					[
						assessment.fileType,
						formatSize(assessment.fileSize),
						t('opencatalogi', 'Redactions: {count}', {
							count: findings.rows.length,
						}),
						assessment.assessedBy
							? t('opencatalogi', 'assessed by {name} on {date}', {
									name: assessment.assessedBy,
									date: String(assessment.assessedAt || '').slice(
										0,
										10,
									),
								})
							: '',
					]
						.filter(Boolean)
						.join(' · ')
				}}
			</p>

			<ol class="woo-redaction__steps">
				<li
					v-for="step in steps"
					:key="step.key"
					:class="{ 'woo-redaction__step--done': step.done }"
					:data-step="step.key">
					<strong>{{ step.label }}</strong>
					<span v-if="step.key === 'verified' && step.done">
						{{
							t('opencatalogi', 'by {name} on {date}', {
								name: step.by,
								date: step.at,
							})
						}}
					</span>
					<span v-else-if="step.key === 'verified'">
						{{
							t(
								'opencatalogi',
								'by nobody yet, a person needs to check every redaction',
							)
						}}
					</span>
					<span v-else-if="step.at">{{ step.at }}</span>
				</li>
			</ol>

			<NcNoteCard v-if="state === 'failed'" type="error">
				{{
					assessment.redactionMessage
					|| t('opencatalogi', 'Redaction failed')
				}}
			</NcNoteCard>
			<NcNoteCard v-else-if="state !== 'checked'" type="warning">
				{{
					t(
						'opencatalogi',
						'This document cannot be published until its redacted version is verified. Publishing the batch is refused while it is open, and the original is never published in its place.',
					)
				}}
			</NcNoteCard>

			<div
				class="woo-redaction__versions"
				role="group"
				:aria-label="t('opencatalogi', 'Version')">
				<NcButton
					:pressed="version === 'redacted'"
					@click="version = 'redacted'">
					{{ t('opencatalogi', 'Redacted version') }}
				</NcButton>
				<NcButton
					:pressed="version === 'original'"
					@click="version = 'original'">
					{{ t('opencatalogi', 'Original') }}
				</NcButton>
			</div>
			<div class="woo-redaction__viewer" :data-version="version">
				<template v-if="shownFileId">
					<img
						:src="previewUrl(shownFileId)"
						:alt="
							version === 'original'
								? t('opencatalogi', 'Original')
								: t('opencatalogi', 'Redacted version')
						"
						class="woo-redaction__preview" />
					<a
						:href="fileUrl(shownFileId)"
						target="_blank"
						rel="noopener noreferrer">
						{{ t('opencatalogi', 'Open in the viewer') }}
					</a>
				</template>
				<p v-else>
					{{ t('opencatalogi', 'There is no file for this version yet.') }}
				</p>
			</div>

			<section
				class="woo-redaction__findings"
				:aria-label="t('opencatalogi', 'Redactions')">
				<h3>
					{{ t('opencatalogi', 'Redactions') }}
					<span class="woo-redaction__counts">{{
						t('opencatalogi', '{kept} kept · {rejected} rejected', {
							kept: findings.kept,
							rejected: findings.rejected,
						})
					}}</span>
				</h3>
				<NcNoteCard v-if="relationsError" type="error">
					{{ relationsError }}
				</NcNoteCard>
				<ol v-if="findings.rows.length > 0" class="woo-redaction__rows">
					<li
						v-for="row in findings.rows"
						:key="row.number"
						:data-state="row.state">
						<span class="woo-redaction__number">{{ row.number }}</span>
						<span>{{ row.what }}</span>
						<span
							v-if="row.grounds.length > 0"
							class="woo-redaction__chip"
							>{{ row.grounds.join(', ') }}</span
						>
						<span class="woo-redaction__state">{{
							findingState(row)
						}}</span>
						<a
							v-if="findingUrl(row)"
							:href="findingUrl(row)"
							target="_blank"
							rel="noopener noreferrer">
							{{ t('opencatalogi', 'Open in OpenRegister') }}
						</a>
					</li>
				</ol>
				<p class="woo-redaction__hint">
					{{
						t(
							'opencatalogi',
							'Findings are decided one by one in OpenRegister.',
						)
					}}
				</p>
			</section>

			<WooRedactionLog
				v-if="Array.isArray(assessment.redactionLog)"
				:log="assessment.redactionLog" />
			<NcNoteCard v-if="assessment.sentBackReason" type="warning">
				{{
					t('opencatalogi', 'Sent back by {by}: {reason}', {
						by: assessment.sentBackReason.by || '',
						reason: assessment.sentBackReason.reason || '',
					})
				}}
			</NcNoteCard>

			<NcNoteCard v-if="verifyError" type="error">
				{{ verifyError }}
			</NcNoteCard>
			<NcButton
				v-if="canVerify"
				variant="primary"
				:disabled="verifying"
				@click="verify">
				{{ t('opencatalogi', 'Mark redaction verified') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.woo-redaction {
	padding: 16px;
}

.woo-redaction__breadcrumb {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.woo-redaction__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.woo-redaction__chip {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	border-radius: var(--border-radius-pill);
	padding: 0 8px;
}

.woo-redaction__chip--warning {
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
}

.woo-redaction__meta,
.woo-redaction__hint,
.woo-redaction__counts {
	color: var(--color-text-maxcontrast);
}

.woo-redaction__counts {
	font-weight: normal;
	margin-inline-start: 8px;
}

.woo-redaction__steps {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-inline-start: 20px;
}

.woo-redaction__step--done strong {
	color: var(--color-success-text, var(--color-main-text));
}

.woo-redaction__versions {
	display: flex;
	gap: 8px;
	margin: 12px 0;
}

.woo-redaction__viewer {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.woo-redaction__preview {
	max-width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.woo-redaction__rows {
	list-style: none;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.woo-redaction__rows li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.woo-redaction__number {
	font-weight: bold;
	min-width: 2ch;
}

.woo-redaction__state {
	color: var(--color-text-maxcontrast);
}
</style>
