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

import { CnPagination, CnSearchPage } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { resultClickTarget } from '../../services/federationResultTarget.js'
import { useSearchStore } from '../../store/modules/search.ts'
import { objectStore } from '../../store/store.js'

export default {
	name: 'FederationSearch',
	components: { CnSearchPage, CnPagination },
	data() {
		return {
			searchStore: useSearchStore(),
			localQuery: '',
		}
	},

	computed: {
		/**
		 * The federation search store's result rows, rendered as-is — no local
		 * filtering, faceting or scoring happens here.
		 *
		 * @return {Array<object>} Result rows from the federation endpoint.
		 * @spec openspec/specs/search/spec.md#requirement-search-ui-components-sch-or-006
		 */
		results() {
			return this.searchStore.searchResults || []
		},

		/**
		 * Total result count as reported by the federation endpoint's pagination
		 * envelope — not counted locally.
		 *
		 * @return {number} Total number of results.
		 * @spec openspec/specs/search/spec.md#requirement-search-ui-components-sch-or-006
		 */
		totalCount() {
			return this.searchStore.pagination?.total || 0
		},

		/**
		 * Whether the federation search store has a request in flight.
		 *
		 * @return {boolean} True while loading.
		 * @spec openspec/specs/search/spec.md#requirement-search-ui-components-sch-or-006
		 */
		loading() {
			return this.searchStore.loading
		},

		/**
		 * Pagination state passed to CnPagination. Falls back to safe
		 * defaults when the search-store hasn't populated pagination yet.
		 *
		 * @spec exclude presentation-only view-model
		 */
		paginationState() {
			const p = this.searchStore.pagination || {}
			return {
				page: p.page || 1,
				pages: p.pages || 1,
				total: p.total || 0,
				limit: p.limit || 20,
			}
		},
	},

	/**
	 * Seed the query input from the store and load the initial result set
	 * through the federation-aware search store.
	 *
	 * @return {void}
	 * @spec openspec/specs/search/spec.md#requirement-search-frontend-store-calls-the-federation-endpoint-sch-or-004
	 */
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
		 * @spec openspec/specs/search/spec.md#requirement-search-frontend-store-calls-the-federation-endpoint-sch-or-004
		 */
		onSearch(payload) {
			const query =
				typeof payload === 'string' ? payload : (payload?.query ?? '')
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

		/**
		 * Refetch results for the newly-selected page. CnPagination emits
		 * a 1-based page number.
		 *
		 * @param {number} newPage The page number selected by the user.
		 * @return {void}
		 * @spec exclude presentation-only pagination
		 */
		onPageChange(newPage) {
			this.searchStore.searchPublications({ _page: newPage })
		},

		/**
		 * Refetch results with a new items-per-page value, resetting to
		 * page 1 so the user doesn't land on an out-of-bounds page when
		 * enlarging the page size mid-navigation.
		 *
		 * @param {number} newSize The page size selected by the user.
		 * @return {void}
		 * @spec exclude presentation-only pagination
		 */
		onPageSizeChange(newSize) {
			this.searchStore.searchPublications({ _limit: newSize, _page: 1 })
		},

		/**
		 * Navigate to a search result's detail. Federated results (carrying
		 * `@self.directory`) open the source instance in a new tab so the
		 * publication is viewed on the authoritative peer; local results
		 * route internally to PublicationDetail.
		 *
		 * @param {object} result Result payload emitted by CnSearchPage.
		 * @return {void}
		 * @spec exclude presentation-only click-through
		 */
		onResultClick(result) {
			const target = resultClickTarget(result, () =>
				objectStore.getCollection('catalog'),
			)
			if (target?.open) {
				window.open(target.open, '_blank', 'noopener,noreferrer')
			} else if (target?.route) {
				this.$router.push(target.route)
			}
		},
	},
}
</script>

<template>
	<div class="federation-search-container">
		<CnSearchPage
			:title="t('opencatalogi', 'Search publications')"
			:query="localQuery"
			:results="results"
			:totalCount="totalCount"
			:loading="loading"
			:placeholder="t('opencatalogi', 'Search across the federated network…')"
			:searchLabel="t('opencatalogi', 'Search')"
			:emptyLabel="
				t('opencatalogi', 'No matching publications across the federation.')
			"
			:idleLabel="
				t(
					'opencatalogi',
					'Start typing to search publications across all connected instances.',
				)
			"
			:loadingLabel="t('opencatalogi', 'Searching the federated network…')"
			@search="onSearch"
			@queryChange="onQueryChange"
			@resultClick="onResultClick">
			<template #result="{ result }">
				<div class="federation-search-result">
					<h4 class="federation-search-result__title">
						{{
							result.title
							|| result['@self']?.name
							|| t('opencatalogi', 'Untitled publication')
						}}
					</h4>
					<p
						v-if="result.summary"
						class="federation-search-result__summary">
						{{ result.summary }}
					</p>
					<p
						v-if="result['@self']?.directory"
						class="federation-search-result__source">
						{{ t('opencatalogi', 'Source:') }}
						{{ result['@self'].directory }}
					</p>
				</div>
			</template>
		</CnSearchPage>
		<CnPagination
			:currentPage="paginationState.page"
			:totalPages="paginationState.pages"
			:totalItems="paginationState.total"
			:currentPageSize="paginationState.limit"
			@pageChanged="onPageChange"
			@pageSizeChanged="onPageSizeChange" />
	</div>
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

/*
 * WOO-523 — local overlap fix. The pinned library version
 * (@conduction/nextcloud-vue beta.141) does not yet ship the WOO-518
 * header padding fix (nextcloud-vue PR #109), so the app-navigation
 * toggle overlaps the "Publicaties zoeken" title. Reserve the 56px
 * inline-start room here via :deep() so the toggle icon does not
 * cover the heading.
 *
 * REMOVE THIS BLOCK once @conduction/nextcloud-vue is bumped to a
 * beta that includes the WOO-518 fix.
 */
:deep(.cn-search-page__header) {
	padding-inline-start: 56px;
}
</style>
