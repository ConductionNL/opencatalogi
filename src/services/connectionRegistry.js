// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Integrations page's Add integration handler.
 *
 * The rows on that page are integriq's `app_connection` objects (hydra change
 * connection-registry, design D8). The page's `connectionStatus` and
 * `connectionSettingsLabel` formatters are nextcloud-vue built-ins, so
 * OpenCatalogi registers no copy of its own: a local formatter under either
 * name would win over the built-in and fall behind it the next time a status
 * is added.
 *
 * Pure: the URL builder and the navigation are passed in, so the module runs
 * under vitest's node environment with nothing mocked.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

/**
 * Where Add integration lands: integriq's Connections overview, preset to this
 * app and opening the link-a-source dialog (hydra connection-registry D9).
 */
export const INTEGRIQ_CONNECTIONS_PATH =
	'/apps/integriq/connections?app=opencatalogi&link=1'

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
