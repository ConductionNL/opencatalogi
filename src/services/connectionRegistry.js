// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Integrations page's two formatters and its Add integration handler.
 *
 * The rows on that page are integriq's `app_connection` objects (hydra change
 * connection-registry, design D8). The installed @conduction/nextcloud-vue
 * 2.37.0 ships neither formatter, so OpenCatalogi carries this copy until a
 * release with the built-ins is pinned. The names are the contract's, so the
 * copies across the fleet stay interchangeable.
 *
 * Pure: the translator, the URL builder and the navigation are passed in, so
 * the module runs under vitest's node environment with nothing mocked. The
 * labels are literal `t('opencatalogi', …)` calls so `npm run check:l10n` sees
 * them.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

/**
 * Where Add integration lands: integriq's Connections overview, preset to this
 * app and opening the link-a-source dialog (hydra connection-registry D9).
 */
export const INTEGRIQ_CONNECTIONS_PATH = '/apps/integriq/connections?app=opencatalogi&link=1'

/**
 * The six registry statuses (design D3). `limited` came with hydra#673: the
 * connection works in part.
 */
export const CONNECTION_STATUSES = Object.freeze([
	'configured',
	'limited',
	'unconfigured',
	'simulated',
	'unavailable',
	'error',
])

/**
 * The translated label for each registry status.
 *
 * @param {function(string, string): string} t Nextcloud's translate, `(app, text)`.
 * @return {Object<string, string>} Status to label.
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */
export function connectionStatusLabels(t) {
	return {
		configured: t('opencatalogi', 'Configured'),
		limited: t('opencatalogi', 'Limited'),
		unconfigured: t('opencatalogi', 'Not configured'),
		simulated: t('opencatalogi', 'Simulated'),
		unavailable: t('opencatalogi', 'Not available'),
		error: t('opencatalogi', 'Error'),
	}
}

/**
 * Build the two connection formatters around a translator.
 *
 * @param {function(string, string): string} t Nextcloud's translate, `(app, text)`.
 * @return {{connectionStatus: function(unknown): string, connectionSettingsLabel: function(unknown): string}} The formatters, keyed by their manifest names.
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */
export function createConnectionFormatters(t) {
	return {
		/**
		 * The label for a status. An unknown value renders itself, because a
		 * status the app cannot name is still a status the admin should see.
		 *
		 * @param {unknown} value The row's `status`.
		 * @return {string} The label, the raw value, or '' when missing.
		 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
		 */
		connectionStatus(value) {
			if (typeof value === 'string' && CONNECTION_STATUSES.includes(value)) {
				return connectionStatusLabels(t)[value]
			}
			return String(value ?? '')
		},

		/**
		 * The Open settings link text, or '' when the row has nowhere to send a
		 * reader. An empty text makes the link cell fall through to plain text.
		 *
		 * @param {unknown} value The row's `settingsUrl`.
		 * @return {string} The link text, or ''.
		 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
		 */
		connectionSettingsLabel(value) {
			return typeof value === 'string' && value.length > 0 ? t('opencatalogi', 'Open settings') : ''
		},
	}
}

/**
 * Build the Add integration header-action handler.
 *
 * A FUNCTION handler because a header action's `navigate` keyword only pushes
 * a route inside this app's router, which cannot leave the app.
 *
 * @param {{generateUrl: function(string): string, assign: function(string): void}} deps Builds the instance URL and navigates to it.
 * @return {{openIntegriqConnections: function(): void}} The handler, keyed by its manifest name.
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */
export function createConnectionHandlers({ generateUrl, assign }) {
	return {
		/**
		 * Open integriq's Connections overview on the link-a-source dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
		 */
		openIntegriqConnections() {
			assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
		},
	}
}
