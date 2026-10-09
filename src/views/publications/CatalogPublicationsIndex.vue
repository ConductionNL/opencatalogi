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
		ref="index"
		:key="indexKey"
		v-bind="indexProps"
		:title="t('opencatalogi', 'Publications')"
		:description="t('opencatalogi', 'Manage your publications and their status')"
		:showTitle="true"
		:register="String(primaryPair.register)"
		:schema="String(primaryPair.schema)"
		:collectionUrl="collectionUrl"
		:rowClickToView="opensDetail"
		:viewTo="opensDetail ? rowTarget : null"
		@rowClick="onRowOpen"
		@rowAuxClick="onRowOpen"
		@view="onView"
		@editOpen="onRowOpen" />
</template>

<script>
import {
	CnIndexPage,
	isNewTabHandled,
	openRowTarget,
} from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import DatabaseAlertOutline from 'vue-material-design-icons/DatabaseAlertOutline.vue'
import DatabaseCogOutline from 'vue-material-design-icons/DatabaseCogOutline.vue'
import DatabaseOffOutline from 'vue-material-design-icons/DatabaseOffOutline.vue'
import { catalogScopePairs, normaliseIdList } from '../../services/catalogScope.js'
import { objectStore } from '../../store/store.js'

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
 * Renders the library's CnIndexPage over all of the catalog's pairs at once,
 * listed from `/api/{catalogSlug}` through its `collectionUrl`. The page's own
 * pair is the publication pair when the catalog holds it, else its first pair.
 * Rows of the publication pair open on PublicationDetail; rows of any other
 * pair have no detail page, so View and Edit open CnIndexPage's form for their
 * own schema. Takes the page config and route params from CnPageRenderer, and
 * passes the rest of the config through to CnIndexPage, `publicationPairConfig`
 * only when the page's pair is the publication pair.
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
		/**
		 * CnIndexPage props that only fit the publication schema, such as its
		 * columns and row actions. Passed when the page's pair is the
		 * publication pair; its custom row actions show on publication rows only.
		 */
		publicationPairConfig: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			/** The catalog from the by-slug lookup, when the menu list lacks it. */
			lookedUpCatalog: null,
			resolving: false,
			failed: false,
			resolveSequence: 0,
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

		/**
		 * The publication register and schema ids, or null when either is unknown.
		 *
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		publicationPair() {
			const [register] = normaliseIdList([this.register])
			const [schema] = normaliseIdList([this.schema])
			return register !== undefined && schema !== undefined
				? { register, schema }
				: null
		},

		/**
		 * The page's own pair for CnIndexPage: the publication pair when the
		 * catalog holds it, else the catalog's first pair.
		 *
		 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
		 */
		primaryPair() {
			return (
				this.pairs.find((pair) => this.isPublicationPair(pair))
				?? this.pairs[0]
				?? null
			)
		},

		/**
		 * The catalog's own list endpoint, which searches all of its pairs at once.
		 *
		 * @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003
		 */
		collectionUrl() {
			return generateUrl('/apps/opencatalogi/api/{catalogSlug}', {
				catalogSlug: this.catalogSlug,
			})
		},

		/** @spec openspec/specs/publications/spec.md#requirement-publication-list-endpoint-must-filter-by-the-catalogs-configured-registers-and-schemas-pub-003 */
		indexKey() {
			const pair = this.primaryPair
			return `${this.catalogSlug}:${pair?.register}:${pair?.schema}`
		},

		/**
		 * Whether the page's pair is the publication pair, which has a detail page.
		 *
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		opensDetail() {
			return this.isPublicationPair(this.primaryPair)
		},

		/** @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003 */
		indexProps() {
			const attrs = { ...this.$attrs }
			for (const listener of RENDERER_ROW_LISTENERS) {
				delete attrs[listener]
			}
			if (!this.opensDetail) {
				return { ...this.actionToggles, ...attrs }
			}
			const config = { ...this.publicationPairConfig }
			if (Array.isArray(config.actions)) {
				// The publication's own actions (e.g. its file list) do not fit a row of another schema.
				config.actions = config.actions.map((action) =>
					action && typeof action === 'object'
						? {
								...action,
								visible: (row) =>
									this.isPublicationRow(row)
									&& (typeof action.visible === 'function'
										? action.visible(row)
										: action.visible !== false),
							}
						: action,
				)
			}
			return { ...this.actionToggles, ...attrs, ...config }
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
		 * Whether a pair is the publication pair.
		 *
		 * @param {{register: number, schema: number}|null} pair The pair.
		 * @return {boolean} True for the publication pair.
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		isPublicationPair(pair) {
			const publication = this.publicationPair
			return Boolean(
				pair
				&& publication
				&& pair.register === publication.register
				&& pair.schema === publication.schema,
			)
		},

		/**
		 * Whether a row is a publication, by its own `@self` register and schema.
		 *
		 * @param {object} row The row.
		 * @return {boolean} True for a publication row.
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		isPublicationRow(row) {
			const self = row?.['@self'] || {}
			const [register] = normaliseIdList([self.register])
			const [schema] = normaliseIdList([self.schema])
			return this.isPublicationPair({ register, schema })
		},

		/**
		 * Where a row opens: its publication's detail page in this catalog.
		 *
		 * @param {object} row The row.
		 * @return {object|null} The router location, or null for a row of another pair or without an id.
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		rowTarget(row) {
			if (!this.isPublicationRow(row)) {
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
		 * ctrl/cmd/shift or middle click opens a publication in a new tab; a row
		 * of another schema opens in the form for its own schema.
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
			// A row of another schema has no detail page; it opens in the form for its own schema, never on a middle click.
			if (!this.isPublicationRow(row)) {
				if (nativeEvent?.button !== 1) {
					this.$refs.index?.openFormDialog(row)
				}
				return
			}
			const target = this.rowTarget(row)
			if (target) {
				openRowTarget(nativeEvent, target, this.$router)
			}
		},

		/**
		 * The View action: a publication opens its detail page, a row of another
		 * schema, which has none, opens in CnIndexPage's form for its own schema.
		 *
		 * @param {object} row The row.
		 * @param {Event} [event] The originating event, when there is one.
		 * @return {void}
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		onView(row, event) {
			if (this.isPublicationRow(row)) {
				this.onRowOpen(row, event)
				return
			}
			this.$refs.index?.openFormDialog(row)
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
</style>
