<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// FederationSearch — bridges CnSearchPage to the federation-aware search store
// so the user-visible Zoeken route returns cross-instance results instead of
// the manifest-driven `type:"search"` default (which fans out per-schema
// against the local OpenRegister and bypasses /api/federation/publications).
//
// References:
//   - WOO-493 manual walkthrough (2026-06-30) — surfaced the missing fetch.
//   - WOO-510 — this fix.
//   - src/store/modules/search.ts — federation-aware store (uses `_search`,
//     hits `/api/federation/publications`).

import { translate as t } from '@nextcloud/l10n'
import { CnSearchPage } from '@conduction/nextcloud-vue'
import { useSearchStore } from '../../store/modules/search.ts'

export default {
	name: 'FederationSearch',
	components: { CnSearchPage },
	data() {
		return {
			searchStore: useSearchStore(),
			localQuery: '',
		}
	},
	computed: {
		results() {
			return this.searchStore.searchResults || []
		},
		totalCount() {
			return this.searchStore.pagination?.total || 0
		},
		loading() {
			return this.searchStore.loading
		},
	},
	mounted() {
		this.localQuery = this.searchStore.searchTerm || ''
		this.searchStore.loadInitialResults()
	},
	methods: {
		t,
		/**
		 * CnSearchPage emits `@search` with a `{ query, facets }` payload —
		 * destructure the query so we don't stringify the whole object into
		 * `_search=[object Object]`.
		 *
		 * @param {{query: string, facets: object}} payload Event payload.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-search-visibility
		 */
		onSearch(payload) {
			const query = typeof payload === 'string' ? payload : (payload?.query ?? '')
			this.searchStore.setSearchTerm(query)
			this.searchStore.searchPublications({ _page: 1 })
		},
		/**
		 * Track the query input so the controlled `:query` prop stays in sync.
		 *
		 * @param {string} query New query string emitted by CnSearchPage.
		 * @return {void}
		 * @spec exclude presentation-only input tracking
		 */
		onQueryChange(query) {
			this.localQuery = query
		},
	},
}
</script>

<template>
	<CnSearchPage
		:title="t('opencatalogi', 'Search publications')"
		:query="localQuery"
		:results="results"
		:total-count="totalCount"
		:loading="loading"
		:placeholder="t('opencatalogi', 'Search across the federated network…')"
		:search-label="t('opencatalogi', 'Search')"
		:empty-label="t('opencatalogi', 'No matching publications across the federation.')"
		:idle-label="t('opencatalogi', 'Start typing to search publications across all connected instances.')"
		:loading-label="t('opencatalogi', 'Searching the federated network…')"
		@search="onSearch"
		@query-change="onQueryChange">
		<template #result="{ result }">
			<div class="federation-search-result">
				<h4 class="federation-search-result__title">
					{{ result.title || result['@self']?.name || t('opencatalogi', 'Untitled publication') }}
				</h4>
				<p v-if="result.summary" class="federation-search-result__summary">
					{{ result.summary }}
				</p>
				<p v-if="result['@self']?.directory" class="federation-search-result__source">
					{{ t('opencatalogi', 'Source:') }} {{ result['@self'].directory }}
				</p>
			</div>
		</template>
	</CnSearchPage>
</template>

<style scoped>
.federation-search-result__title {
	margin: 0 0 4px;
	font-size: 16px;
	font-weight: 600;
}
.federation-search-result__summary {
	margin: 0 0 6px;
	color: var(--color-text-maxcontrast);
}
.federation-search-result__source {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-lighter);
}
</style>
