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
import { CnSearchPage, CnPagination } from '@conduction/nextcloud-vue'
import { useSearchStore } from '../../store/modules/search.ts'

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
		results() {
			return this.searchStore.searchResults || []
		},
		totalCount() {
			return this.searchStore.pagination?.total || 0
		},
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
			const rawDirectory = result?.['@self']?.directory
			// The publication's catalog can arrive in three shapes depending
			// on backend extension: a full TCatalogi object (when the store
			// extends `@self.catalog`), a bare slug string, or a raw catalog
			// ID that would need mapping. Prefer the object's `.slug`, fall
			// back to a string-shaped catalog, else leave null and skip
			// navigation (avoids `/publications/<numeric-id>/...` which the
			// slug-based detail resolver cannot find).
			const catalogSource = result?.['@self']?.catalog ?? result?.catalog
			const catalogSlug = typeof catalogSource === 'string'
				? catalogSource
				: (catalogSource?.slug || null)
			const publicationId = result?.id || result?.['@self']?.id
			// Local publications carry directory === 'local' (see
			// PublicationService::getLocalPublicationsFast); anything else is
			// a federated peer identifier.
			const isFederated = rawDirectory && rawDirectory !== 'local'
			if (isFederated) {
				// Federation payloads currently carry `@self.directory` as a
				// bare hostname (e.g. `canary.commonground.nu`), not a full
				// URL. Strip any accidental protocol / path / port if present,
				// then compose the peer's OpenCatalogi app-root explicitly.
				const bareHost = String(rawDirectory)
					.replace(/^https?:\/\//i, '')
					.replace(/\/.*$/, '')
					.replace(/:$/, '')
				if (!bareHost) return
				const peerRoot = `https://${bareHost}/index.php/apps/opencatalogi`
				// Federation result payloads do not currently carry the peer's
				// catalog slug on individual results, so a deep-link to
				// PublicationDetail (`/#/publications/<slug>/<id>`) would 404
				// on the peer. Fall back to the peer's search page pre-filled
				// with the publication id — the user lands on the peer's
				// OpenCatalogi with the specific result surfaced.
				const target = publicationId
					? `${peerRoot}/#/search?_search=${encodeURIComponent(publicationId)}`
					: `${peerRoot}/`
				window.open(target, '_blank', 'noopener,noreferrer')
				return
			}
			// Local result — route inside the app. Requires a resolved slug;
			// silently skip if the catalog shape wasn't recognised so the
			// user isn't sent to a broken detail URL.
			if (catalogSlug && publicationId) {
				this.$router.push({
					name: 'PublicationDetail',
					params: { catalogSlug: String(catalogSlug), id: String(publicationId) },
				})
			}
		},
		/**
		 * Refetch results for the newly-selected page. CnPagination emits
		 * a 1-based page number.
		 *
		 * @param {number} newPage The page number selected by the user.
		 * @return {void}
		 * @spec openspec/specs/federation/spec.md#requirement-federated-search-visibility
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
		 * @spec openspec/specs/federation/spec.md#requirement-federated-search-visibility
		 */
		onPageSizeChange(newSize) {
			this.searchStore.searchPublications({ _limit: newSize, _page: 1 })
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
			:total-count="totalCount"
			:loading="loading"
			:placeholder="t('opencatalogi', 'Search across the federated network…')"
			:search-label="t('opencatalogi', 'Search')"
			:empty-label="t('opencatalogi', 'No matching publications across the federation.')"
			:idle-label="t('opencatalogi', 'Start typing to search publications across all connected instances.')"
			:loading-label="t('opencatalogi', 'Searching the federated network…')"
			@search="onSearch"
			@query-change="onQueryChange"
			@result-click="onResultClick">
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
		<CnPagination
			:current-page="paginationState.page"
			:total-pages="paginationState.pages"
			:total-items="paginationState.total"
			:current-page-size="paginationState.limit"
			@page-changed="onPageChange"
			@page-size-changed="onPageSizeChange" />
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
