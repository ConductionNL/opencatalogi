/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Minimal stub for @nextcloud/axios used by the Vitest unit suite. The pure
 * usage-stats helpers do not perform requests under test; this stub only keeps
 * the module importable offline.
 */
const axios = {
	get: async () => ({ data: {} }),
	post: async () => ({ data: {} }),
}

export default axios
