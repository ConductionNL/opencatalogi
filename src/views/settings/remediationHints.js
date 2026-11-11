/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Remediation hints of the admin settings checks, keyed by the
 * machine-readable failure or skip reason. Each entry translates its hint
 * with the translator it is given.
 */
const REMEDIATION_HINTS = {
	'http-404': (t) =>
		t(
			'opencatalogi',
			'Not found (404) — check webserver routing/rewrites for this URL.',
		),

	'ssrf-blocked': (t) =>
		t(
			'opencatalogi',
			'Blocked as an unsafe outbound target — check the configured public base URL.',
		),

	'network-error': (t) =>
		t(
			'opencatalogi',
			'Could not connect — check that this instance is reachable from the public internet.',
		),

	'invalid-xml': (t) =>
		t(
			'opencatalogi',
			'Response was not well-formed XML.',
		),

	'no-diwoo-elements': (t) =>
		t(
			'opencatalogi',
			'No DIWOO metadata elements found — the category sitemap may be empty.',
		),

	'diwoo-xsd-invalid': (t) =>
		t(
			'opencatalogi',
			'DIWOO metadata failed validation — see the DIWOO validation report for this catalog.',
		),

	'diwoo-validation-error': (t) =>
		t(
			'opencatalogi',
			'Could not run DIWOO validation for this catalog/category.',
		),

	'missing-sitemap-reference': (t) =>
		t(
			'opencatalogi',
			'The root robots.txt does not reach the app: it names no Woo sitemap.',
		),

	'sitemapindex-unreachable': (t) =>
		t(
			'opencatalogi',
			'Skipped — the sitemapindex for this catalog was not reachable.',
		),

	'sitemapindex-invalid': (t) =>
		t(
			'opencatalogi',
			'Skipped — the sitemapindex for this catalog was not well-formed.',
		),

	'no-sitemap-pages': (t) =>
		t(
			'opencatalogi',
			'Skipped — the sitemapindex has no sitemap pages to sample.',
		),

	'no-publications-found': (t) =>
		t(
			'opencatalogi',
			'Skipped — no publication URL was found to sample.',
		),

	'request-cap-reached': (t) =>
		t(
			'opencatalogi',
			'Skipped — the per-run outbound request cap was reached.',
		),

	'not-registered': (t) =>
		t(
			'opencatalogi',
			'Not yet registered with the Woo-index.',
		),

	'registration-pending': (t) =>
		t(
			'opencatalogi',
			'Registration requested but not yet confirmed.',
		),

	'url-mismatch': (t) =>
		t(
			'opencatalogi',
			"The registered URL does not match this instance's public base URL.",
		),
}

/**
 * The short remediation hint of a check reason, or the reason itself when
 * there is no hint for it.
 *
 * @param {string} reason - The machine-readable reason code
 * @param {Function} t - The translator
 * @return {string} A human-readable remediation hint
 */
export function remediationHintFor(reason, t) {
	return Object.hasOwn(REMEDIATION_HINTS, reason)
		? REMEDIATION_HINTS[reason](t)
		: reason
}
