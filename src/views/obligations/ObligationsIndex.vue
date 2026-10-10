<template>
	<div class="obligations">
		<header class="obligations__header">
			<h2>{{ t('opencatalogi', 'Woo obligations') }}</h2>
			<p v-if="overview" class="obligations__lede">
				{{ n('opencatalogi', '%n record the Woo requires to be public', '%n records the Woo requires to be public', overview.total || 0) }}
			</p>
		</header>

		<NcNoteCard v-if="loaded && !isAdmin" type="info">
			{{ t('opencatalogi', 'Only an administrator can see the Woo obligations.') }}
		</NcNoteCard>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-else-if="loading" :name="t('opencatalogi', 'Loading the Woo obligations')" />

		<template v-else-if="page">
			<NcNoteCard v-if="page.unreadSources.length > 0" type="warning">
				<p>{{ t('opencatalogi', 'These sources could not be read. Their obligations are missing from the figures below.') }}</p>
				<ul class="obligations__unread">
					<li v-for="source in page.unreadSources" :key="source.appId">
						<strong>{{ source.appId }}</strong>: {{ source.reason }}
					</li>
				</ul>
			</NcNoteCard>

			<section class="obligations__tiles" :aria-label="t('opencatalogi', 'Totals')">
				<div class="obligations__tile">
					<span class="obligations__tile-label">{{ t('opencatalogi', 'Still to publish') }}</span>
					<span class="obligations__tile-value">{{ page.tiles.outstanding }}</span>
					<span class="obligations__tile-note">{{ n('opencatalogi', 'from %n source system', 'from %n source systems', page.tiles.sources) }}</span>
				</div>
				<div class="obligations__tile">
					<span class="obligations__tile-label">{{ t('opencatalogi', 'Due this week') }}</span>
					<span class="obligations__tile-value">{{ page.tiles.dueThisWeek }}</span>
				</div>
				<div class="obligations__tile obligations__tile--late">
					<span class="obligations__tile-label">{{ t('opencatalogi', 'Late') }}</span>
					<span class="obligations__tile-value">{{ page.tiles.late }}</span>
					<span class="obligations__tile-note">{{ t('opencatalogi', 'past the Woo term') }}</span>
				</div>
				<div class="obligations__tile">
					<span class="obligations__tile-label">{{ t('opencatalogi', 'Published this month') }}</span>
					<span class="obligations__tile-value">{{ page.tiles.publishedThisMonth }}</span>
					<span class="obligations__tile-note">{{ t('opencatalogi', 'on time: {count}', { count: page.tiles.onTimeThisMonth }) }}</span>
				</div>
			</section>

			<section class="obligations__section">
				<h3>{{ t('opencatalogi', 'Per source system') }}</h3>
				<table class="obligations__table">
					<thead>
						<tr>
							<th scope="col">{{ t('opencatalogi', 'Source system') }}</th>
							<th scope="col">{{ t('opencatalogi', 'Still to publish') }}</th>
							<th scope="col">{{ t('opencatalogi', 'Next deadline') }}</th>
							<th scope="col">{{ t('opencatalogi', 'Late') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in page.perSource" :key="row.source">
							<td>{{ row.source }}</td>
							<td>{{ row.outstanding }}</td>
							<td>{{ row.nextDue ? formatDate(row.nextDue) : t('opencatalogi', 'Unknown') }}</td>
							<td>{{ row.late > 0 ? n('opencatalogi', '%n late', '%n late', row.late) : t('opencatalogi', 'None') }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="obligations__section">
				<h3>{{ t('opencatalogi', 'Late first, then by deadline') }}</h3>
				<NcEmptyContent v-if="page.rows.length === 0" :name="t('opencatalogi', 'Nothing left to publish')" />
				<table v-else class="obligations__table">
					<thead>
						<tr>
							<th scope="col">{{ t('opencatalogi', 'Record') }}</th>
							<th scope="col">{{ t('opencatalogi', 'Source') }}</th>
							<th scope="col">{{ t('opencatalogi', 'Category') }}</th>
							<th scope="col">{{ t('opencatalogi', 'Deadline') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in page.rows" :key="index">
							<td>{{ row.title }}</td>
							<td>{{ row.source }}</td>
							<td>{{ row.category }}</td>
							<td>
								<template v-if="row.dueDate">
									{{ formatDate(row.dueDate) }}
								</template>
								<template v-else>
									{{ t('opencatalogi', 'Unknown') }}
								</template>
								<span v-if="row.late" class="obligations__late">
									{{ n('opencatalogi', 'Late, %n day', 'Late, %n days', row.daysLate) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The Woo-verplichtingen page (REQ-WOO-003, board OcWooVerplichtingen): the
// totals, the table per source system and the outstanding records, late
// first. The unread sources stand above the figures, because a source that
// could not be read is missing from them, not empty.

import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { useIsAdmin } from '../../composables/useIsAdmin.js'
import { obligationPage } from '../../services/obligationOverview.js'

export default {
	name: 'ObligationsIndex',
	components: { NcEmptyContent, NcLoadingIcon, NcNoteCard },
	setup() {
		const { isAdmin, loaded } = useIsAdmin()
		return { isAdmin, loaded }
	},
	data() {
		return {
			overview: null,
			loading: true,
			error: '',
		}
	},
	computed: {
		/**
		 * The page model, or null before the overview arrived.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-an-admin-can-open-the-overview-as-a-page-req-woo-003
		 */
		page() {
			return this.overview ? obligationPage(this.overview, new Date()) : null
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		n,
		/**
		 * Read the overview from the backend.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-an-admin-can-open-the-overview-as-a-page-req-woo-003
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(generateUrl('/apps/opencatalogi/api/obligations'))
				this.overview = response.data
			} catch (e) {
				this.error = e?.response?.data?.message
					|| t('opencatalogi', 'The Woo obligations could not be read. This is not an overview with nothing to publish.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * A deadline as the reader's date.
		 *
		 * @param {string} value An ISO 8601 date.
		 * @return {string}
		 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-an-admin-can-open-the-overview-as-a-page-req-woo-003
		 */
		formatDate(value) {
			return new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
		},
	},
}
</script>

<style scoped>
.obligations {
	padding: 20px;
}

.obligations__lede {
	color: var(--color-text-maxcontrast);
}

.obligations__unread {
	margin: 8px 0 0 20px;
	list-style: disc;
}

.obligations__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
	margin: 16px 0;
}

.obligations__tile {
	display: flex;
	flex-direction: column;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.obligations__tile--late {
	border-color: var(--color-error);
}

.obligations__tile-label {
	color: var(--color-text-maxcontrast);
}

.obligations__tile-value {
	font-size: 28px;
	font-weight: bold;
}

.obligations__tile-note {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.obligations__section {
	margin-top: 24px;
}

.obligations__table {
	width: 100%;
	border-collapse: collapse;
}

.obligations__table th,
.obligations__table td {
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.obligations__late {
	display: inline-block;
	margin-inline-start: 8px;
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}
</style>
