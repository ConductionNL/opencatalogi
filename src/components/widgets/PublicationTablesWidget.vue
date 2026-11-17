<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publication-tables" data-testid="publication-tables">
		<p v-if="!tables.length && !attachments.length" class="publication-tables__empty">
			{{ t('opencatalogi', 'No tables yet. Add a CSV attachment to publish it as a table.') }}
		</p>

		<ul v-if="attachments.length" class="publication-tables__attachments">
			<li
				v-for="attachment in attachments"
				:key="attachment.id"
				class="publication-tables__attachment">
				<span>{{ attachment.label }}</span>
				<NcButton
					:disabled="busy"
					:data-testid="'publication-table-publish-' + attachment.id"
					@click="publish(attachment.id)">
					{{ t('opencatalogi', 'Publish as table') }}
				</NcButton>
			</li>
		</ul>

		<section
			v-for="table in tables"
			:key="table.id"
			class="publication-tables__table"
			:data-testid="'publication-table-' + table.id">
			<h3 class="publication-tables__title">
				{{ table.title }}
			</h3>
			<p class="publication-tables__status">
				{{ statusLabel(table) }}
			</p>
			<a
				v-if="table.status === 'ready' && rowsUrl(table)"
				:href="rowsUrl(table)"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('opencatalogi', 'Try the query in the browser') }}
			</a>

			<table v-if="table.status === 'ready'" class="publication-tables__dictionary">
				<thead>
					<tr>
						<th scope="col">
							{{ t('opencatalogi', 'Column') }}
						</th>
						<th scope="col">
							{{ t('opencatalogi', 'Title') }}
						</th>
						<th scope="col">
							{{ t('opencatalogi', 'Description') }}
						</th>
						<th scope="col">
							{{ t('opencatalogi', 'Type') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="column in edits[table.id] || []" :key="column.name">
						<td>{{ column.name }}</td>
						<td>
							<NcTextField
								:modelValue="column.title"
								:label="t('opencatalogi', 'Title of {column}', { column: column.name })"
								@update:modelValue="column.title = $event" />
						</td>
						<td>
							<NcTextField
								:modelValue="column.description"
								:label="t('opencatalogi', 'Description of {column}', { column: column.name })"
								@update:modelValue="column.description = $event" />
						</td>
						<td>{{ column.type }}</td>
					</tr>
				</tbody>
			</table>
			<NcButton
				v-if="table.status === 'ready'"
				variant="primary"
				:disabled="busy"
				:data-testid="'publication-table-save-' + table.id"
				@click="saveDictionary(table)">
				{{ t('opencatalogi', 'Save dictionary') }}
			</NcButton>

			<div
				v-if="table.status === 'ready'"
				class="publication-tables__views"
				:data-testid="'publication-table-views-' + table.id">
				<h4 class="publication-tables__title">
					{{ t('opencatalogi', 'Views on the public page') }}
				</h4>
				<p v-if="!(views[table.id] || []).length" class="publication-tables__status">
					{{ t('opencatalogi', 'No views yet. Readers see the rows only through the query link.') }}
				</p>
				<ol v-else class="publication-tables__view-list">
					<li
						v-for="(view, index) in views[table.id]"
						:key="view.id"
						class="publication-tables__view"
						:data-testid="'publication-table-view-' + view.id">
						<span>{{ view.title }} ({{ kindLabel(view.kind) }})</span>
						<span class="publication-tables__view-actions">
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:aria-label="t('opencatalogi', 'Edit {title}', { title: view.title })"
								:data-testid="'publication-table-view-edit-' + view.id"
								@click="openDialog(table, view)">
								{{ t('opencatalogi', 'Edit') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy || index === 0"
								:aria-label="t('opencatalogi', 'Move {title} up', { title: view.title })"
								:data-testid="'publication-table-view-up-' + view.id"
								@click="moveView(table, index, -1)">
								{{ t('opencatalogi', 'Move up') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy || index === views[table.id].length - 1"
								:aria-label="t('opencatalogi', 'Move {title} down', { title: view.title })"
								:data-testid="'publication-table-view-down-' + view.id"
								@click="moveView(table, index, 1)">
								{{ t('opencatalogi', 'Move down') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:aria-label="t('opencatalogi', 'Remove {title}', { title: view.title })"
								:data-testid="'publication-table-view-remove-' + view.id"
								@click="removeView(table, view)">
								{{ t('opencatalogi', 'Remove') }}
							</NcButton>
						</span>
					</li>
				</ol>
				<NcButton
					:disabled="busy"
					:data-testid="'publication-table-add-view-' + table.id"
					@click="openDialog(table, null)">
					{{ t('opencatalogi', 'Show on the public page') }}
				</NcButton>
			</div>
		</section>

		<DatasetViewDialog
			v-if="dialog.table"
			:open="dialog.open"
			:publicationId="resolvedObjectId"
			:catalogSlug="catalogSlug"
			:table="dialog.table"
			:view="dialog.view"
			:position="(views[dialog.table.id] || []).length"
			@saved="loadViews(dialog.table)"
			@close="dialog.open = false" />

		<NcNoteCard
			v-if="message"
			:type="messageType"
			data-testid="publication-tables-message">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import DatasetViewDialog from '../../modals/DatasetViewDialog.vue'
import { movedViews, viewPayload } from '../../services/datasetViews.js'
import { filesRouteParams } from '../../services/publicationVisibility.js'
import { csvAttachments, dictionaryChanges } from '../../services/publishedTables.js'
import { injectedObjectContext } from './objectContext.js'

/**
 * The publication's published tables (REQ-ODT-001, REQ-ODT-003): Publish as
 * table on each CSV attachment that is not a table yet, and per table its row
 * count, a link to try the query, and the data dictionary to edit.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
export default {
	name: 'PublicationTablesWidget',
	components: { DatasetViewDialog, NcButton, NcNoteCard, NcTextField },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},

	props: {
		register: { type: String, default: '' },
		schema: { type: [String, Object], default: '' },
		objectId: { type: String, default: '' },
		content: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			tables: [],
			attachments: [],
			edits: {},
			views: {},
			dialog: { open: false, table: null, view: null },
			busy: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/** @spec exclude presentational widget adapter; resolves the object context from inject. */
		ctx() {
			return injectedObjectContext(this)
		},

		/** @spec exclude presentational widget adapter; derives the object id. */
		resolvedObjectId() {
			return String(this.objectId || this.ctx.objectId || this.content.objectId || '')
		},

		/** @spec exclude presentational widget adapter; the catalogue of the page's route. */
		catalogSlug() {
			return String(this.content.catalogSlug || this.$route?.params?.catalogSlug || '')
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/**
			 * Read the tables once the publication id is known.
			 *
			 * @param {string} id The publication id.
			 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
			 */
			handler(id) {
				if (id) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * The editor endpoint of this publication's tables.
		 *
		 * @param {string} [path] The path after `tables`.
		 * @return {string} The URL.
		 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
		 */
		url(path = '') {
			return generateUrl('/apps/opencatalogi/api/publications/{id}/tables' + path, { id: this.resolvedObjectId })
		},

		/**
		 * The public rows URL of a table, for trying the query.
		 *
		 * @param {{id: string}} table The table.
		 * @return {string} The URL, or '' without a catalogue.
		 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
		 */
		rowsUrl(table) {
			if (!this.catalogSlug) {
				return ''
			}
			return generateUrl('/apps/opencatalogi/api/{catalogSlug}/{publicationId}/tables/{tableId}/rows', {
				catalogSlug: this.catalogSlug,
				publicationId: this.resolvedObjectId,
				tableId: table.id,
			})
		},

		/**
		 * A table's state in words.
		 *
		 * @param {{status: string, rowCount?: number, lastError?: string}} table The table.
		 * @return {string} The label.
		 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
		 */
		statusLabel(table) {
			if (table.status === 'ready') {
				return this.t('opencatalogi', 'Rows: {count}', { count: table.rowCount || 0 })
			}
			if (table.status === 'failed') {
				return this.t('opencatalogi', 'Not published: {error}', { error: table.lastError || '' })
			}
			return this.t('opencatalogi', 'Importing the rows…')
		},

		/**
		 * Read the tables, then the attachments that can still become one.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
		 */
		async load() {
			try {
				const { data } = await axios.get(this.url())
				this.tables = data.results || []
			} catch {
				this.tables = []
			}
			this.edits = Object.fromEntries(
				this.tables.map((table) => [table.id, (table.dictionary || []).map((column) => ({ ...column }))]),
			)
			await Promise.all(this.tables.filter((table) => table.status === 'ready').map((table) => this.loadViews(table)))

			try {
				const { data } = await axios.get(generateUrl(
					'/apps/openregister/api/objects/{register}/{schema}/{id}/files',
					filesRouteParams({ register: this.register, schema: this.schema, ctx: this.ctx, id: this.resolvedObjectId }),
				))
				this.attachments = csvAttachments(data, this.tables)
			} catch {
				this.attachments = []
			}
		},

		/**
		 * Publish one CSV attachment as a table.
		 *
		 * @param {string} fileId The attachment.
		 * @return {Promise<void>}
		 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
		 */
		async publish(fileId) {
			this.busy = true
			try {
				const { data } = await axios.post(this.url(), { fileId })
				this.messageType = data.status === 'failed' ? 'error' : 'success'
				this.message = data.status === 'failed'
					? this.t('opencatalogi', 'Not published: {error}', { error: data.lastError || '' })
					: this.t('opencatalogi', 'The table is published.')
				await this.load()
			} catch (error) {
				this.showError(error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Save the titles and descriptions changed in one table's dictionary.
		 *
		 * @param {{id: string, dictionary: Array<object>}} table The table.
		 * @return {Promise<void>}
		 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
		 */
		async saveDictionary(table) {
			const columns = dictionaryChanges(table.dictionary || [], this.edits[table.id] || [])
			if (!columns.length) {
				this.messageType = 'info'
				this.message = this.t('opencatalogi', 'Nothing changed.')
				return
			}
			this.busy = true
			try {
				const { data } = await axios.put(this.url('/' + encodeURIComponent(table.id) + '/dictionary'), { columns })
				table.dictionary = data.columns
				this.edits = { ...this.edits, [table.id]: data.columns.map((column) => ({ ...column })) }
				this.messageType = 'success'
				this.message = this.t('opencatalogi', 'The dictionary is saved.')
			} catch (error) {
				this.showError(error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Read the saved views of one table, in page order.
		 *
		 * @param {{id: string}} table The table.
		 * @return {Promise<void>}
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		async loadViews(table) {
			let results
			try {
				const { data } = await axios.get(this.url('/' + encodeURIComponent(table.id) + '/views'))
				results = data.results || []
			} catch {
				results = []
			}
			this.views = { ...this.views, [table.id]: results }
		},

		/**
		 * A view's kind in words.
		 *
		 * @param {string} kind The kind.
		 * @return {string} The label.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		kindLabel(kind) {
			const labels = {
				table: this.t('opencatalogi', 'Table'),
				bar: this.t('opencatalogi', 'Bar chart'),
				line: this.t('opencatalogi', 'Line chart'),
				map: this.t('opencatalogi', 'Map'),
			}
			return labels[kind] || kind
		},

		/**
		 * Open the view dialog for a table, to add a view or change one.
		 *
		 * @param {object} table The table.
		 * @param {object|null} view The view to change, or null to add one.
		 * @return {void}
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		openDialog(table, view) {
			this.dialog = { open: false, table, view }
			this.$nextTick(() => {
				this.dialog.open = true
			})
		},

		/**
		 * Swap a view with its neighbour and save both positions.
		 *
		 * @param {{id: string}} table The table.
		 * @param {number} index The view to move.
		 * @param {number} delta -1 for up, 1 for down.
		 * @return {Promise<void>}
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		async moveView(table, index, delta) {
			const moved = movedViews(this.views[table.id] || [], index, delta)
			if (moved === null) {
				return
			}
			this.busy = true
			try {
				for (const { view, position } of moved) {
					await axios.post(this.url('/' + encodeURIComponent(table.id) + '/views'), viewPayload(view, String(view.id), position))
				}
			} catch (error) {
				this.showError(error)
			} finally {
				await this.loadViews(table)
				this.busy = false
			}
		},

		/**
		 * Remove one view from the public page.
		 *
		 * @param {{id: string}} table The table.
		 * @param {{id: string, title: string}} view The view.
		 * @return {Promise<void>}
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
		 */
		async removeView(table, view) {
			this.busy = true
			try {
				await axios.delete(this.url('/' + encodeURIComponent(table.id) + '/views/' + encodeURIComponent(view.id)))
				this.messageType = 'success'
				this.message = this.t('opencatalogi', 'The view "{title}" is removed.', { title: view.title })
			} catch (error) {
				this.showError(error)
			} finally {
				await this.loadViews(table)
				this.busy = false
			}
		},

		/**
		 * Put a refusal into words.
		 *
		 * @param {object} error The axios error.
		 * @return {void}
		 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
		 */
		showError(error) {
			const response = error && error.response ? error.response : {}
			this.messageType = 'error'
			if (response.status === 403) {
				this.message = this.t('opencatalogi', 'You may not change this publication.')
				return
			}
			if (response.status === 400 && response.data && response.data.message) {
				this.message = response.data.message
				return
			}
			this.message = this.t('opencatalogi', 'That did not work. Try again.')
		},
	},
}
</script>

<style scoped>
.publication-tables {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.publication-tables__empty,
.publication-tables__status,
.publication-tables__title {
	margin: 0;
}

.publication-tables__attachments {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.publication-tables__attachment {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.publication-tables__table {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.publication-tables__views {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.publication-tables__view-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	width: 100%;
	margin: 0;
	padding-inline-start: 20px;
}

.publication-tables__view {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	color: var(--color-main-text);
}

.publication-tables__view-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.publication-tables__dictionary {
	width: 100%;
	border-collapse: collapse;
}

.publication-tables__dictionary th,
.publication-tables__dictionary td {
	padding: 4px 8px;
	text-align: start;
	vertical-align: top;
	color: var(--color-main-text);
}

.publication-tables__dictionary th {
	color: var(--color-text-maxcontrast);
	font-weight: bold;
}
</style>
