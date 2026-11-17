/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin calls behind a catalogue's Feed consumers section: list, add,
 * issue a key, revoke a key, disable a consumer. A generated key is answered
 * once by the server and never stored readable, so nothing here keeps it.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The consumers endpoint of one catalogue, with an optional suffix.
 *
 * @param {string} catalogSlug The catalogue's slug.
 * @param {string} [suffix] Appended path.
 * @return {string} The URL.
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
export function consumersUrl(catalogSlug, suffix = '') {
	return generateUrl('/apps/opencatalogi/api/catalogs/{catalogSlug}/consumers', { catalogSlug }) + suffix
}

/**
 * The catalogue's consumers and the instance-wide accounts.
 *
 * @param {string} catalogSlug The catalogue's slug.
 * @return {Promise<{consumers: Array<object>, instanceAccounts: Array<string>}>} The listing.
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
 */
export async function listConsumers(catalogSlug) {
	const { data } = await axios.get(consumersUrl(catalogSlug))
	return {
		consumers: Array.isArray(data?.results) ? data.results : [],
		instanceAccounts: Array.isArray(data?.instanceAccounts) ? data.instanceAccounts.map(String) : [],
	}
}

/**
 * Add a consumer.
 *
 * @param {string} catalogSlug The catalogue's slug.
 * @param {{name: string, contact: string}} input The consumer.
 * @return {Promise<object>} The created consumer.
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
export async function addConsumer(catalogSlug, input) {
	const { data } = await axios.post(consumersUrl(catalogSlug), { name: input.name, contact: input.contact })
	return data
}

/**
 * Issue a key; the answer holds the key, shown once.
 *
 * @param {string} catalogSlug The catalogue's slug.
 * @param {string} consumerId The consumer.
 * @return {Promise<{key: string, consumer: object}>} The key and the consumer.
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
 */
export async function generateKey(catalogSlug, consumerId) {
	const { data } = await axios.post(consumersUrl(catalogSlug, `/${encodeURIComponent(consumerId)}/keys`), {})
	return data
}

/**
 * Revoke one key.
 *
 * @param {string} catalogSlug The catalogue's slug.
 * @param {string} consumerId The consumer.
 * @param {string} keyId The key.
 * @return {Promise<object>} The consumer.
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
 */
export async function revokeKey(catalogSlug, consumerId, keyId) {
	const { data } = await axios.post(consumersUrl(catalogSlug, `/${encodeURIComponent(consumerId)}/keys/${encodeURIComponent(keyId)}/revoke`), {})
	return data
}

/**
 * Disable a consumer: every key of it stops working.
 *
 * @param {string} catalogSlug The catalogue's slug.
 * @param {string} consumerId The consumer.
 * @return {Promise<object>} The consumer.
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
export async function disableConsumer(catalogSlug, consumerId) {
	const { data } = await axios.post(consumersUrl(catalogSlug, `/${encodeURIComponent(consumerId)}/disable`), {})
	return data
}

/**
 * Whether a key still opens the feed: not revoked and not past its expiry.
 *
 * @param {object} key A key as listed (no hash).
 * @param {Date} [now] The moment to judge against.
 * @return {boolean}
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
 */
export function isActiveKey(key, now = new Date()) {
	if (!key || key.revokedAt) {
		return false
	}
	return !key.expiresAt || new Date(key.expiresAt) > now
}
