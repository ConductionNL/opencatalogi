<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section
		v-if="documents.length > 1"
		class="attachment-order"
		data-testid="attachment-order">
		<h3 class="attachment-order__title">
			{{ t('opencatalogi', 'Order on the portal') }}
		</h3>
		<ol class="attachment-order__list">
			<li
				v-for="(doc, index) in documents"
				:key="doc.id"
				class="attachment-order__item"
				:data-testid="'attachment-order-item-' + doc.id">
				<span class="attachment-order__label">{{ doc.label }}</span>
				<NcButton
					:ref="'up-' + doc.id"
					variant="tertiary"
					:aria-label="
						t('opencatalogi', 'Move {name} up', { name: doc.label })
					"
					:disabled="index === 0 || saving"
					data-testid="attachment-order-up"
					@click="move(index, -1)">
					<template #icon>
						<ArrowUp :size="20" />
					</template>
				</NcButton>
				<NcButton
					:ref="'down-' + doc.id"
					variant="tertiary"
					:aria-label="
						t('opencatalogi', 'Move {name} down', { name: doc.label })
					"
					:disabled="index === documents.length - 1 || saving"
					data-testid="attachment-order-down"
					@click="move(index, 1)">
					<template #icon>
						<ArrowDown :size="20" />
					</template>
				</NcButton>
			</li>
		</ol>
		<p
			class="hidden-visually"
			aria-live="polite"
			data-testid="attachment-order-live">
			{{ announcement }}
		</p>
		<NcButton
			variant="secondary"
			:disabled="!changed || saving"
			data-testid="attachment-order-save"
			@click="save">
			{{ t('opencatalogi', 'Save order') }}
		</NcButton>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import {
	moveDocument,
	orderedDocuments,
	storedOrder,
} from '../../services/attachmentOrder.js'
import { documentOptions } from '../../services/publicationVisibility.js'

/**
 * AttachmentOrderList: Move up and Move down buttons on each of a publication's
 * documents, operable by keyboard, announcing the new place through a live
 * region. Save order writes `attachmentOrder` on the publication, which the
 * public attachment list then follows (REQ-PDP-001).
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-an-officer-orders-a-publication-s-documents-and-the-public-list-keeps-that-order-req-pdp-001
 */
export default {
	name: 'AttachmentOrderList',
	components: { ArrowDown, ArrowUp, NcButton, NcNoteCard },

	props: {
		/** The files and object route parameters: register, schema and id. */
		route: { type: Object, required: true },
		/** The publication's stored order. */
		order: { type: Array, default: () => [] },
	},

	data() {
		return {
			documents: [],
			saved: [],
			announcement: '',
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/** @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.2 */
		changed() {
			return storedOrder(this.documents).join('\n') !== this.saved.join('\n')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the publication's files and put them in the stored order.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.2
		 */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}/{id}/files',
						this.route,
					),
				)
				this.documents = orderedDocuments(documentOptions(data), this.order)
			} catch {
				this.documents = []
			}

			this.saved = storedOrder(this.documents)
		},

		/**
		 * Move one document a place up or down, announce its new place and keep focus on it.
		 *
		 * @param {number} index The document's position, from 0.
		 * @param {number} delta -1 for up, 1 for down.
		 * @return {void}
		 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.2
		 */
		move(index, delta) {
			const doc = this.documents[index]
			this.documents = moveDocument(this.documents, index, delta)
			const position = this.documents.indexOf(doc) + 1
			this.announcement = t(
				'opencatalogi',
				'{name} is now number {position} of {total}',
				{
					name: doc.label,
					position,
					total: this.documents.length,
				},
			)
			this.message = ''
			this.$nextTick(() => {
				const atEdge = position === 1 || position === this.documents.length
				let direction = delta < 0 ? 'up' : 'down'
				if (atEdge) {
					direction = position === 1 ? 'down' : 'up'
				}
				const button = this.$refs[direction + '-' + doc.id]?.[0]
				button?.$el?.focus()
			})
		},

		/**
		 * Store the order on the publication.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#1.2
		 */
		async save() {
			this.saving = true
			const order = storedOrder(this.documents)
			try {
				await axios.patch(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}/{id}',
						this.route,
					),
					{ attachmentOrder: order },
				)
				this.saved = order
				this.messageType = 'success'
				this.message = t(
					'opencatalogi',
					'The order is saved. The portal shows the documents in this order.',
				)
			} catch {
				this.messageType = 'error'
				this.message = t(
					'opencatalogi',
					'The order could not be saved. Try again.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.attachment-order {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.attachment-order__title {
	font-size: var(--default-font-size);
	font-weight: bold;
}

.attachment-order__list {
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	margin-block: calc(var(--default-grid-baseline) * 2);
}

.attachment-order__item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.attachment-order__label {
	flex: 1;
	overflow-wrap: anywhere;
}
</style>
