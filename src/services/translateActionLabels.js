/**
 * Translate the action labels of one config object.
 *
 * @param {object|undefined} config A config that may hold `actions`.
 * @param {(key: string) => string} translate Maps a source string to its translation.
 * @return {object|undefined} The same config without actions, else a copy with translated labels.
 */
function translateConfigActions(config, translate) {
	if (!Array.isArray(config?.actions)) {
		return config
	}
	return {
		...config,
		actions: config.actions.map((action) =>
			action
			&& typeof action === 'object'
			&& typeof action.label === 'string'
				? { ...action, label: translate(action.label) }
				: action,
		),
	}
}

/**
 * Translate every `pages[].config.actions[].label` in a manifest, and every
 * `pages[].config.publicationPairConfig.actions[].label`, the actions the
 * Publications page passes on its publication pair only.
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
			if (!page?.config) {
				return page
			}
			let config = translateConfigActions(page.config, translate)
			const pairConfig = config.publicationPairConfig
			const translatedPairConfig = translateConfigActions(pairConfig, translate)
			if (translatedPairConfig !== pairConfig) {
				config = { ...config, publicationPairConfig: translatedPairConfig }
			}
			return config === page.config ? page : { ...page, config }
		}),
	}
}
