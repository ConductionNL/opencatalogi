/**
 * Resolve the active Nextcloud theme ('light' | 'dark') from body data-theme attributes.
 *
 * @return {string} 'light' or 'dark'
 * @spec openspec/changes/retrofit-2026-05-25-content-management/tasks.md#task-5
 */
export const getTheme = () => {
	if (document.body.hasAttribute('data-theme-light')) {
		return 'light'
	}
	if (document.body.hasAttribute('data-theme-default')) {
		return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
	}
	return 'dark'
}
