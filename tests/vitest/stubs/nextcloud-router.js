/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Minimal stub for @nextcloud/router used by the Vitest unit suite.
 * The real package reads the NC base URL from the runtime; under Vitest
 * we only need deterministic, prefix-stable URL builders.
 */

export function generateUrl(url) {
	return `/index.php${url.startsWith('/') ? url : `/${url}`}`
}

export function generateRemoteUrl(service) {
	return `http://localhost/remote.php/${service}`
}

export function generateOcsUrl(url) {
	return `/ocs/v2.php${url.startsWith('/') ? url : `/${url}`}`
}
