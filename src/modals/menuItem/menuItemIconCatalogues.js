/**
 * menuItemIconCatalogues.js
 * Builds the icon catalogues passed to the shared CnIconPicker (via the
 * schema-driven `widget: 'icon'` field of CnFormDialog).
 *
 * The @conduction/nextcloud-vue library bundles no icon pack — the consumer
 * (OpenCatalogi) owns and licenses the data and passes it in through the
 * adapters. FontAwesome is built from the packs OpenCatalogi already ships;
 * MDI is loaded by the picker itself from the optional @mdi/js dependency;
 * OpenGemeenten is a small hand-curated CC0 sample for this demo (the full set
 * lives at gemeenteniconen.nl — CC0 icons; the npm package is CC BY-NC-ND).
 *
 * @category Services
 * @package opencatalogi
 * @license AGPL-3.0-or-later
 */

import { fromFontAwesome, fromOpenGemeenten } from '@conduction/nextcloud-vue'
import { fas } from '@fortawesome/free-solid-svg-icons'
import { far } from '@fortawesome/free-regular-svg-icons'
import { fab } from '@fortawesome/free-brands-svg-icons'

/**
 * A small CC0 OpenGemeenten sample so the "OpenGemeenten" source tab renders
 * something in this demo. Simple 24×24 glyphs standing in for municipal
 * top-task icons — replace with the real set from gemeenteniconen.nl for
 * production use.
 *
 * @type {Array<{name: string, label: string, path: string}>}
 */
const OPENGEMEENTEN_SAMPLE = [
	{ name: 'paspoort', label: 'Paspoort', path: 'M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm6 4a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm-4 11a4 4 0 0 1 8 0Z' },
	{ name: 'rijbewijs', label: 'Rijbewijs', path: 'M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm2 3v2h6V8Zm0 4v2h6v-2Zm9-4v6h4V8Z' },
	{ name: 'verhuizen', label: 'Verhuizen', path: 'M12 3 2 12h3v8h6v-6h2v6h6v-8h3Z' },
	{ name: 'afval', label: 'Afval', path: 'M9 3h6l1 2h4v2H4V5h4Zm-3 4h12l-1 13H7Z' },
	{ name: 'parkeren', label: 'Parkeren', path: 'M6 3h7a5 5 0 0 1 0 10H9v8H6Zm3 3v4h4a2 2 0 0 0 0-4Z' },
	{ name: 'belasting', label: 'Belasting', path: 'M4 3h16v4H4Zm2 6h12v12H6Zm3 3v2h6v-2Zm0 4v2h6v-2Z' },
	{ name: 'zorg', label: 'Zorg', path: 'M12 21 4 13a5 5 0 0 1 7-7l1 1 1-1a5 5 0 0 1 7 7Z' },
	{ name: 'melding', label: 'Melding', path: 'M12 2a7 7 0 0 0-7 7c0 5-2 6-2 6h18s-2-1-2-6a7 7 0 0 0-7-7Zm0 20a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2Z' },
	{ name: 'trouwen', label: 'Trouwen', path: 'M8 9a4 4 0 1 0 8 0 4 4 0 0 0-8 0Zm-2 0a6 6 0 0 1 6-6 6 6 0 0 1 6 6 6 6 0 0 1-6 6 6 6 0 0 1-6-6Zm3 8h6l2 5H7Z' },
	{ name: 'bouwen', label: 'Bouwen', path: 'M3 21V11l9-7 9 7v10h-6v-6h-6v6Z' },
]

/**
 * Build the `catalogues` map for CnIconPicker's enriched multi-source mode.
 *
 * @return {{fontawesome: Array<object>, opengemeenten: Array<object>}} the catalogues.
 */
export function buildMenuItemIconCatalogues() {
	return {
		fontawesome: fromFontAwesome({ fas, far, fab }),
		opengemeenten: fromOpenGemeenten(OPENGEMEENTEN_SAMPLE),
	}
}
