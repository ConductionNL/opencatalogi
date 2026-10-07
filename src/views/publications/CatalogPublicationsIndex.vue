<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div v-if="status === 'loading'" class="catalog-publications__loading">
		<NcLoadingIcon :size="64" />
	</div>
	<NcEmptyContent
		v-else-if="status === 'error'"
		:name="t('opencatalogi', 'Could not load the catalog')"
		:description="t('opencatalogi', 'Reload the page to try again.')">
		<template #icon>
			<DatabaseAlertOutline :size="64" />
		</template>
	</NcEmptyContent>
	<NcEmptyContent
		v-else-if="status === 'not-found'"
		:name="t('opencatalogi', 'Catalog not found')"
		:description="
			t('opencatalogi', 'No catalog has the slug {slug}.', {
				slug: catalogSlug,
			})
		">
		<template #icon>
			<DatabaseOffOutline :size="64" />
		</template>
	</NcEmptyContent>
	<NcEmptyContent
		v-else-if="pairs.length === 0"
		:name="
			t('opencatalogi', 'This catalog has no registers or schemas configured')
		"
		:description="
			t(
				'opencatalogi',
				'Add a register and a schema to the catalog to list its publications here.',
			)
		">
		<template #icon>
			<DatabaseCogOutline :size="64" />
		</template>
		<template v-if="catalogRoute" #action>
			<NcButton :to="catalogRoute">
				{{ t('opencatalogi', 'Open catalog') }}
			</NcButton>
		</template>
	</NcEmptyContent>
	<CnIndexPage
		v-else
		:key="indexKey"
		v-bind="indexProps"
		:title="t('opencatalogi', 'Publications')"
		:description="catalog.title"
		:register="String(activePair.register)"
		:schema="String(activePair.schema)"
		:rowClickToView="opensDetail"
		:viewTo="opensDetail ? rowTarget : null"
		@rowClick="onRowOpen"
		@rowAuxClick="onRowOpen"
		@view="onRowOpen"
		@editOpen="onRowOpen">
		<template v-if="pairs.length > 1" #below-header>
			<NcSelect
				class="catalog-publications__pair"
				:modelValue="activeOption"
				:options="pairOptions"
				label="label"
				:clearable="false"
				:inputLabel="t('opencatalogi', 'Register and schema')"
				@update:modelValue="onPairSelected" />
		</template>
	</CnIndexPage>
</template>

<script>
import {
	CnIndexPage,
	isNewTabHandled,
	openRowTarget,
} from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import DatabaseAlertOutline from 'vue-material-design-icons/DatabaseAlertOutline.vue'
import DatabaseCogOutline from 'vue-material-design-icons/DatabaseCogOutline.vue'
import DatabaseOffOutline from 'vue-material-design-icons/DatabaseOffOutline.vue'
import { catalogScopePairs, normaliseIdList } from '../../services/catalogScope.js'
import { objectStore } from '../../store/store.js'

/** The query key holding the active pair. Underscored, so CnIndexPage does not send it to the API as a filter. */
const PAIR_QUERY_KEY = '_pair'

/** Listeners CnPageRenderer binds for its own row opening, which only works on `type:"index"` pages. */
const RENDERER_ROW_LISTENERS = [
	'onView',
	'onRowClick',
	'onRowAuxClick',
	'onEditOpen',
]

/**
 * CatalogPublicationsIndex: the publications of one catalog, scoped like the
 * backend's `/api/{catalogSlug}`, to the catalog's registers × schemas.
 *
 * Renders the library's CnIndexPage for one register/schema pair at a time.
 * With several pairs a selector picks the active one, kept in the `_pair`
 * query parameter. Rows of the publication pair open on PublicationDetail;
 * rows of any other pair have no detail page, so a click selects them and
 * Edit uses CnIndexPage's own form for that schema. Takes the page config and
 * route params from CnPageRenderer, and passes the rest of the config through
 * to CnIndexPage.
 *
 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
 */
export default {
	name: 'CatalogPublicationsIndex',
	components: {
		CnIndexPage,
		DatabaseAlertOutline,
		DatabaseCogOutline,
		DatabaseOffOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},

	inheritAttrs: false,

	props: {
		/** The catalog slug, from the route. */
		catalogSlug: { type: String, required: true },
		/** The page's show and selectable toggles, spread onto CnIndexPage. */
		actionToggles: { type: Object, default: () => ({}) },
		/**
		 * The publication register and schema ids, the pair PublicationDetail
		 * shows. CnIndexPage gets the catalog's active pair instead.
		 */
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			/** The catalog from the by-slug lookup, when the menu list lacks it. */
			lookedUpCatalog: null,
			resolving: false,
			failed: false,
			resolveSequence: 0,
			/** Register and schema titles for the selector, keyed `register:<id>` / `schema:<id>`. */
			scopeTitles: {},
		}
	},

	computed: {
		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		menuCatalogs() {
			return objectStore.menuCatalogs
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		menuCatalog() {
			return (
				this.menuCatalogs.find(
					(catalog) => catalog.slug === this.catalogSlug,
				) ?? null
			)
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		catalog() {
			if (this.menuCatalog) {
				return this.menuCatalog
			}
			return this.lookedUpCatalog?.slug === this.catalogSlug
				? this.lookedUpCatalog
				: null
		},

		/**
		 * @return {'loading'|'error'|'not-found'|'ready'}
		 *
		 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
		 */
		status() {
			if (this.catalog) {
				return 'ready'
			}
			if (this.resolving) {
				return 'loading'
			}
			return this.failed ? 'error' : 'not-found'
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		pairs() {
			return catalogScopePairs(this.catalog)
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		activePair() {
			const key = this.$route?.query?.[PAIR_QUERY_KEY]
			return (
				this.pairs.find((pair) => pair.key === key) ?? this.pairs[0] ?? null
			)
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		indexKey() {
			const pair = this.activePair
			return `${this.catalogSlug}:${pair?.register}:${pair?.schema}`
		},

		/**
		 * Whether the active pair is the publication pair, which has a detail page.
		 *
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		opensDetail() {
			const [register] = normaliseIdList([this.register])
			const [schema] = normaliseIdList([this.schema])
			const pair = this.activePair
			return Boolean(
				pair
				&& register !== undefined
				&& schema !== undefined
				&& pair.register === register
				&& pair.schema === schema,
			)
		},

		/** @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003 */
		indexProps() {
			const attrs = { ...this.$attrs }
			for (const listener of RENDERER_ROW_LISTENERS) {
				delete attrs[listener]
			}
			// CnIndexPage's View only emits; without a detail page it would do nothing.
			const view = this.opensDetail ? {} : { showViewAction: false }
			return { ...this.actionToggles, ...attrs, ...view }
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		pairOptions() {
			return this.pairs.map((pair) => ({
				key: pair.key,
				label: t('opencatalogi', '{schema} in {register}', {
					schema: this.scopeTitles[`schema:${pair.schema}`] ?? pair.schema,
					register:
						this.scopeTitles[`register:${pair.register}`]
						?? pair.register,
				}),
			}))
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		activeOption() {
			return (
				this.pairOptions.find(
					(option) => option.key === this.activePair?.key,
				) ?? null
			)
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		catalogRoute() {
			const id = this.catalog?.id
			return id ? { name: 'CatalogDetail', params: { id: String(id) } } : null
		},
	},

	watch: {
		catalogSlug: {
			immediate: true,
			/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
			handler() {
				this.resolveCatalog()
			},
		},

		/**
		 * A reloaded menu list can drop the catalog or change its scope. A
		 * catalog the list holds updates through `menuCatalog`; one it lacks is
		 * looked up again.
		 *
		 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
		 */
		menuCatalogs() {
			if (!this.resolving && !this.menuCatalog) {
				this.resolveCatalog({ walk: false })
			}
		},

		pairs: {
			immediate: true,
			/**
			 * @param {Array<{register: number, schema: number}>} pairs The pairs.
			 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
			 */
			handler(pairs) {
				if (pairs.length > 1) {
					this.loadScopeTitles(pairs)
				}
			},
		},
	},

	methods: {
		/**
		 * Find the catalog for the current slug. The menu list is the source;
		 * on a deep link it may still be loading, so wait for it, and look the
		 * slug up on its own when the list does not hold it.
		 *
		 * @param {object} [options] Options.
		 * @param {boolean} [options.walk] Wait for the menu list first; false right after it reloaded.
		 * @return {Promise<void>}
		 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
		 */
		async resolveCatalog({ walk = true } = {}) {
			const sequence = ++this.resolveSequence
			const isCurrent = () => sequence === this.resolveSequence
			const slug = this.catalogSlug
			this.failed = false
			if (this.menuCatalog) {
				this.resolving = false
				return
			}

			this.resolving = true
			try {
				if (walk) {
					// 0 joins a walk already in flight, or starts one when none is.
					await objectStore.fetchMenuCatalogs(0).catch((error) => {
						// eslint-disable-next-line no-console
						console.warn(
							'Failed to load the catalog menu entries:',
							error,
						)
					})
					if (!isCurrent() || this.menuCatalog) {
						return
					}
				}
				const catalog = await objectStore.fetchMenuCatalogBySlug(slug)
				if (isCurrent()) {
					this.lookedUpCatalog = catalog
				}
			} catch (error) {
				if (isCurrent()) {
					this.failed = true
				}
				// eslint-disable-next-line no-console
				console.warn(`Failed to look up the catalog "${slug}":`, error)
			} finally {
				if (isCurrent()) {
					this.resolving = false
				}
			}
		},

		/**
		 * Load the register and schema titles the selector shows. An id whose
		 * title does not load stays shown as the id. Each id is requested once;
		 * its null placeholder marks the request as in flight.
		 *
		 * @param {Array<{register: number, schema: number}>} pairs The pairs.
		 * @return {Promise<void>}
		 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
		 */
		async loadScopeTitles(pairs) {
			const wanted = new Set()
			for (const pair of pairs) {
				wanted.add(`register:${pair.register}`)
				wanted.add(`schema:${pair.schema}`)
			}
			const missing = [...wanted].filter((key) => !(key in this.scopeTitles))
			if (missing.length === 0) {
				return
			}
			const placeholders = Object.fromEntries(
				missing.map((key) => [key, null]),
			)
			this.scopeTitles = { ...this.scopeTitles, ...placeholders }
			await Promise.all(
				missing.map(async (key) => {
					const [kind, id] = key.split(':')
					try {
						const response = await fetch(
							generateUrl(`/apps/openregister/api/${kind}s/{id}`, {
								id,
							}),
						)
						if (!response.ok) return
						const data = await response.json()
						if (typeof data?.title === 'string' && data.title !== '') {
							this.scopeTitles = {
								...this.scopeTitles,
								[key]: data.title,
							}
						}
					} catch {
						// The id stays as the label.
					}
				}),
			)
		},

		/**
		 * Switch the list to another pair. Starts from a clean query: the
		 * previous pair's filters, search and sort belong to another schema.
		 *
		 * @param {{key: string}|null} option The selected option.
		 * @return {void}
		 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
		 */
		onPairSelected(option) {
			if (!option || option.key === this.activePair?.key) {
				return
			}
			this.$router
				.push({ query: { [PAIR_QUERY_KEY]: option.key } })
				.catch(() => {})
		},

		/**
		 * Where a row opens: its publication's detail page in this catalog.
		 *
		 * @param {object} row The row.
		 * @return {object|null} The router location, or null for a row of another pair or without an id.
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		rowTarget(row) {
			if (!this.opensDetail) {
				return null
			}
			const self = row?.['@self'] || {}
			const id = row?.id ?? self.id ?? self.uuid ?? row?.uuid
			if (id === undefined || id === null || id === '') {
				return null
			}
			return {
				name: 'PublicationDetail',
				params: { catalogSlug: this.catalogSlug, id: String(id) },
			}
		},

		/**
		 * Open a row on a click, a middle click or the View action. A
		 * ctrl/cmd/shift or middle click opens it in a new tab.
		 *
		 * @param {object} row The row.
		 * @param {Event} [event] The originating event, when there is one.
		 * @return {void}
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		onRowOpen(row, event) {
			const nativeEvent =
				typeof Event !== 'undefined' && event instanceof Event
					? event
					: undefined
			if (isNewTabHandled(nativeEvent)) {
				return
			}
			const target = this.rowTarget(row)
			if (target) {
				openRowTarget(nativeEvent, target, this.$router)
			}
		},
	},
}
</script>

<style scoped>
.catalog-publications__loading {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 10);
}

.catalog-publications__pair {
	max-width: 480px;
}
</style>
