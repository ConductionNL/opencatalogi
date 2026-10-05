/**
 * Translate every `pages[].config.actions[].label` in a manifest.
 *
 * CnRowActions renders a manifest action's label as-is, so the app has to
 * hand the library an already translated string. Returns a new manifest and
 * leaves the input untouched; pages and actions without a string label are
 * passed through unchanged, and so are string entries such as the
 * `"builtin:edit"` placeholders, which the library labels itself.
 *
 * @param {object} manifest The manifest (with `pages[]`).
 * @param {(key: string) => string} translate Maps a source string to its translation.
 * @return {object} A new manifest with translated action labels.
 */
export function translateActionLabels(manifest, translate) {
	const pages = Array.isArray(manifest?.pages) ? manifest.pages : null
	if (!pages) {
		return manifest
	}
	return {
		...manifest,
		pages: pages.map((page) => {
			const actions = page?.config?.actions
			if (!Array.isArray(actions)) {
				return page
			}
			return {
				...page,
				config: {
					...page.config,
					actions: actions.map((action) =>
						action
						&& typeof action === 'object'
						&& typeof action.label === 'string'
							? { ...action, label: translate(action.label) }
							: action,
					),
				},
			}
		}),
	}
}
