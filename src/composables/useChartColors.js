/**
 * Theme-aware chart color palette, resolved from Nextcloud CSS variables at
 * runtime instead of hardcoded hex literals — ApexCharts' `colors` option
 * needs concrete color strings, so this reads `getComputedStyle(documentElement)`
 * once per call rather than baking in a fixed palette that ignores nldesign
 * theme overrides / dark mode (ADR-004 frontend, ADR-010 NL Design).
 *
 * @spec openspec/changes/nc-css-vars-color-cleanup/tasks.md#task-1
 */

/**
 * Read an NC CSS custom property's resolved value, with a fallback for the
 * (test/SSR) case where `document` isn't a real themed Nextcloud page.
 * @param {string} name - The CSS custom property name, e.g. '--color-primary-element'.
 * @param {string} fallback - The value to use when the variable isn't set.
 * @return {string} The resolved color value.
 */
function readCssVar(name, fallback) {
	if (typeof document === 'undefined' || typeof getComputedStyle === 'undefined') {
		return fallback
	}
	const value = getComputedStyle(document.documentElement).getPropertyValue(name)?.trim()
	return value || fallback
}

/**
 * Resolve an ordered categorical color palette for donut/bar-style charts
 * from NC theme variables, falling back to the same visual palette the
 * dashboard previously hardcoded when a variable isn't defined (e.g. an older
 * Nextcloud core without one of the newer semantic tokens).
 * @return {string[]} An ordered list of resolved color strings.
 */
export function useCategoricalChartColors() {
	return [
		readCssVar('--color-primary-element', '#0082C9'),
		readCssVar('--color-success', '#059669'),
		readCssVar('--color-warning', '#D97706'),
		readCssVar('--color-error', '#DC2626'),
		readCssVar('--color-favorite', '#7C3AED'),
		readCssVar('--color-info', '#0891B2'),
		readCssVar('--color-primary-element-light', '#DB2777'),
	]
}

/**
 * Resolve the single accent color used by the traffic (API read-requests) chart.
 * @return {string[]} A single-entry color list, matching ApexCharts' `colors` shape.
 */
export function useAccentChartColor() {
	return [readCssVar('--color-primary-element', '#079cff')]
}
