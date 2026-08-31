/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lightweight stub for @conduction/nextcloud-vue used by the Vitest unit
 * suite. The real package ships the whole CnAppRoot manifest shell + Vue 2
 * SFCs; under offline Vitest we only need the handful of non-component
 * helpers a store module imports (buildHeaders, validateValue). Components
 * are exported as inert markers so a bare `import { CnX }` never crashes.
 */

/**
 * Stub of buildHeaders — the real helper injects the NC request token. For
 * unit tests we just return a deterministic JSON header set.
 *
 * @return {object}
 */
export function buildHeaders() {
	return { 'Content-Type': 'application/json' }
}

/**
 * Stub of validateValue — pass-through "valid" result.
 *
 * @return {{ success: boolean }}
 */
export function validateValue() {
	return { success: true }
}

// Inert component markers — never rendered in the node-env unit suite.
const Stub = { name: 'CnStub' }
export const CnAppRoot = Stub
export const CnDashboardPage = Stub
export const CnStatsBlock = Stub
export const CnChartWidget = Stub
export const CnDetailPage = Stub
export const CnDetailGrid = Stub
export const CnJsonViewer = Stub
export const CnIndexPage = Stub
export const CnRowActions = Stub
export const CnStatusBadge = Stub
export const CnMetadataTab = Stub
export const CnPropertiesTab = Stub
export const CnVersionInfoCard = Stub
export const useListView = () => ({})
