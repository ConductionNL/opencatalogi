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
			const rawDirectory = result?.['@self']?.directory
			const publicationId = result?.id || result?.['@self']?.id
			// Derive the catalog slug once, up front, so BOTH branches can
			// reach it. Reading `catalogSlug` inside the federated branch
			// while the `const` declaration lived below the local branch was
			// a TDZ ReferenceError that silently swallowed every federated
			// click — the handler threw before window.open ran. The catalog
			// field can arrive in three shapes depending on backend extend:
			// a full TCatalogi object, a bare slug string, or a raw catalog
			// ID (which we can't map to a slug here, so we fall through to
			// null and let the caller decide the fallback).
			const catalogSource = result?.['@self']?.catalog ?? result?.catalog
			const catalogSlug = typeof catalogSource === 'string'
				? catalogSource
				: (catalogSource?.slug || null)
			// Local publications carry directory === 'local' (see
			// PublicationService::getLocalPublicationsFast); anything else is
			// a federated peer identifier.
			const isFederated = rawDirectory && rawDirectory !== 'local'
			if (isFederated) {
				// `@self.directory` is a peer *identifier* (bare hostname —
				// often the docker-network name like `nc-fed-2`, which is
				// not reachable from the user's browser). `@self.uri`, on
				// the other hand, is the peer's authoritative object URL
				// (`https://canary.…/apps/openregister/api/objects/…` or
				// `http://localhost:9082/apps/openregister/…`) — the only
				// origin we know the current viewer can actually load. Use
				// its origin (scheme+host+port) as the base for the peer
				// deep-link. Fall back to the bare-hostname derivation for
				// legacy payloads that pre-date the `uri` field, so we
				// don't regress on any older peer.
				let peerOrigin = null
				const peerUri = result?.['@self']?.uri
				if (peerUri) {
					try {
						peerOrigin = new URL(peerUri).origin
					} catch {
						// URL parse failed — leave peerOrigin null so we
						// fall through to the bare-hostname branch.
					}
				}
				if (!peerOrigin) {
					const bareHost = String(rawDirectory)
						.replace(/^https?:\/\//i, '')
						.replace(/\/.*$/, '')
						.replace(/:$/, '')
					if (!bareHost) return
					peerOrigin = `https://${bareHost}`
				}
				const peerRoot = `${peerOrigin}/index.php/apps/opencatalogi`
				// Deep-link to PublicationDetail only when we can actually
				// build a valid URL: BOTH the catalog slug (present on the
				// individual result payload) AND the publication id must be
				// resolvable. Otherwise fall back to the peer's app-root so
				// the user lands on the correct instance and can navigate —
				// guessing a catalog slug (`publications`) would 404 on any
				// peer that uses a different default and there is no way to
				// verify the assumption from the payload alone.
				const target = (catalogSlug && publicationId)
					? `${peerRoot}/#/publications/${encodeURIComponent(catalogSlug)}/${encodeURIComponent(publicationId)}`
					: `${peerRoot}/`
				window.open(target, '_blank', 'noopener,noreferrer')
				return
			}
			// Local result — route inside the app. Skip navigation when the
			// slug is unresolvable (avoids `/publications/<numeric-id>/…`
			// which the slug-based detail resolver cannot find).
			if (catalogSlug && publicationId) {
				this.$router.push({
					name: 'PublicationDetail',
					params: { catalogSlug: String(catalogSlug), id: String(publicationId) },
				})
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
