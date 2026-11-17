<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { refusalOf } from '../../services/feedConsumerRefusal.js'
import {
	addConsumer,
	disableConsumer,
	generateKey,
	isActiveKey,
	listConsumers,
	revokeKey,
} from '../../services/feedConsumers.js'

/**
 * A catalogue's feed consumers, for an administrator: add a consumer, issue
 * a key (shown once), revoke a key, disable a consumer. The instance-wide
 * accounts that read every catalogue are listed read-only beside them.
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
export default {
	name: 'FeedConsumersPanel',
	components: { NcButton, NcNoteCard, NcTextField },

	props: {
		/** The catalogue's slug. */
		catalogSlug: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			consumers: [],
			instanceAccounts: [],
			name: '',
			contact: '',
			newKey: '',
			busy: false,
			error: '',
		}
	},

	watch: {
		/**
		 * Reload for another catalogue.
		 *
		 * @return {void}
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
		 */
		catalogSlug() {
			this.load()
		},
	},

	created() {
		this.load()
	},

	methods: {
		t,
		isActiveKey,

		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
		 */
		async load() {
			await this.run(async () => {
				const listing = await listConsumers(this.catalogSlug)
				this.consumers = listing.consumers
				this.instanceAccounts = listing.instanceAccounts
			})
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
		 */
		async add() {
			await this.run(async () => {
				await addConsumer(this.catalogSlug, { name: this.name.trim(), contact: this.contact.trim() })
				this.name = ''
				this.contact = ''
				await this.load()
			})
		},

		/**
		 * @param {object} consumer The consumer.
		 * @return {Promise<void>}
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
		 */
		async issue(consumer) {
			await this.run(async () => {
				const answer = await generateKey(this.catalogSlug, consumer.id)
				this.newKey = answer.key
				await this.load()
			})
		},

		/**
		 * @param {object} consumer The consumer.
		 * @param {object} key The key.
		 * @return {Promise<void>}
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
		 */
		async revoke(consumer, key) {
			await this.run(async () => {
				await revokeKey(this.catalogSlug, consumer.id, key.id)
				await this.load()
			})
		},

		/**
		 * @param {object} consumer The consumer.
		 * @return {Promise<void>}
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
		 */
		async disable(consumer) {
			await this.run(async () => {
				await disableConsumer(this.catalogSlug, consumer.id)
				await this.load()
			})
		},

		/**
		 * Run one admin call, showing its refusal instead of failing silently.
		 *
		 * @param {() => Promise<void>} work The call.
		 * @return {Promise<void>}
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
		 */
		async run(work) {
			this.busy = true
			this.error = ''
			try {
				await work()
			} catch (e) {
				this.error = refusalOf(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {string|undefined} value An ISO moment.
		 * @return {string} It as a reader sees it, or ''.
		 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
		 */
		moment(value) {
			return value ? new Date(value).toLocaleString() : ''
		},
	},
}
</script>

<template>
	<section class="feed-consumers">
		<NcNoteCard v-if="newKey" type="warning">
			<p>{{ t('opencatalogi', 'This key is shown once. Copy it now: it cannot be shown again.') }}</p>
			<code class="feed-consumers__key">{{ newKey }}</code>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="consumers.length === 0">
			{{ t('opencatalogi', 'No feed consumers yet.') }}
		</p>
		<ul v-else class="feed-consumers__list">
			<li v-for="consumer in consumers" :key="consumer.id" class="feed-consumers__consumer">
				<strong>{{ consumer.name }}</strong>
				<span v-if="consumer.contact"> · {{ consumer.contact }}</span>
				<span v-if="consumer.disabled"> · {{ t('opencatalogi', 'Disabled') }}</span>
				<ul class="feed-consumers__keys">
					<li v-for="key in consumer.keys || []" :key="key.id">
						<span>{{ key.label || key.id }}</span>
						<span> · {{ t('opencatalogi', 'Created') }} {{ moment(key.createdAt) }}</span>
						<span v-if="key.lastUsedAt"> · {{ t('opencatalogi', 'Last used') }} {{ moment(key.lastUsedAt) }}</span>
						<NcButton
							v-if="isActiveKey(key)"
							variant="tertiary"
							:disabled="busy"
							@click="revoke(consumer, key)">
							{{ t('opencatalogi', 'Revoke') }}
						</NcButton>
					</li>
				</ul>
				<div v-if="!consumer.disabled" class="feed-consumers__actions">
					<NcButton :disabled="busy" @click="issue(consumer)">
						{{ t('opencatalogi', 'Generate key') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="disable(consumer)">
						{{ t('opencatalogi', 'Disable') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<form class="feed-consumers__add" @submit.prevent="add">
			<NcTextField v-model="name" :label="t('opencatalogi', 'Name')" />
			<NcTextField v-model="contact" :label="t('opencatalogi', 'Contact')" />
			<NcButton type="submit" :disabled="busy || name.trim() === ''">
				{{ t('opencatalogi', 'Add') }}
			</NcButton>
		</form>

		<div v-if="instanceAccounts.length > 0" class="feed-consumers__instance">
			<h4>{{ t('opencatalogi', 'Accounts that read every catalogue\'s feed') }}</h4>
			<ul>
				<li v-for="account in instanceAccounts" :key="account">
					{{ account }}
				</li>
			</ul>
		</div>
	</section>
</template>

<style scoped>
.feed-consumers {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.feed-consumers__list,
.feed-consumers__keys {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 0;
	list-style: none;
}

.feed-consumers__keys {
	padding-inline-start: 16px;
	color: var(--color-text-maxcontrast);
}

.feed-consumers__key {
	display: block;
	overflow-wrap: anywhere;
	user-select: all;
}

.feed-consumers__actions,
.feed-consumers__add {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}
</style>
