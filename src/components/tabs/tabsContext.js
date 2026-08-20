/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Injection key shared by `<AppTabs>` and `<AppTab>`.
 *
 * ⚠️ It lives in its OWN module on purpose. A `Symbol()` declared inside a
 * component file is module-local, so if that file is ever reached through two
 * different specifiers (a `require()` and an ESM `import`, or a package alias
 * plus a relative path), the provide and the inject use two different symbols
 * and `inject()` silently returns its fallback — a dead component with no
 * error. One tiny module with one specifier removes that failure mode.
 */
export const TABS_CONTEXT = Symbol('opencatalogi:tabs')
