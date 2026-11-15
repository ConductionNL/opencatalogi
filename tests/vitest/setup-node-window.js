/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The library's schema utils import @nextcloud/auth, whose browser storage
 * reads `window` when the module loads. This suite runs in node, so `window`
 * is the global object.
 */
globalThis.window ??= globalThis
