<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnThemePreview
		v-if="pickers.length"
		:pickers="pickers"
		:defaults="defaults"
		:value="value"
		:sample-title="sampleTitle"
		:sample-body-text="sampleBodyText" />
	<NcEmptyContent v-else :name="t('opencatalogi', 'No theme colours to preview yet')">
		<template #icon>
			<Web :size="20" />
		</template>
	</NcEmptyContent>
</template>

<script>
import { CnThemePreview } from '@conduction/nextcloud-vue'
import { NcEmptyContent } from '@nextcloud/vue'
import Web from 'vue-material-design-icons/Web.vue'
import { translate as t } from '@nextcloud/l10n'

/**
 * ThemePreviewWidget — thin adapter that renders the library's CnThemePreview
 * under the manifest `theme-preview` widget key.
 *
 * CnThemePreview requires a non-empty `pickers` array (no prop default) and
 * has no internal guard for a missing/undefined one — the ThemeDetail page's
 * manifest entry never sets `content.pickers` (the Theme schema has no colour
 * fields to seed it from), so mounting the raw library component there threw
 * `TypeError: this.pickers is not iterable` in `buildInitialModel()`, which
 * left `model` undefined and made the `previewStyle` computed throw
 * `Cannot convert undefined or null to object` right after. This adapter
 * guarantees a valid, non-empty `pickers`/`defaults`/`value` shape (from
 * `content` when the manifest supplies one, else a sensible catalog-brand
 * default) so the library component never sees an undefined prop, and falls
 * back to an empty-state card in the defensive case where `pickers` still
 * resolves empty.
 */
export default {
	name: 'ThemePreviewWidget',
	components: { CnThemePreview, NcEmptyContent, Web },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},
	props: {
		/** Catalog widget content blob (CnDetailPage body path). */
		content: { type: Object, default: () => ({}) },
	},
	data() {
		return {
			/** Fallback colour-picker declarations used when the manifest supplies none. */
			defaultPickers: [
				{ key: 'primary', label: t('opencatalogi', 'Primary'), default: '#21468B' },
				{ key: 'background', label: t('opencatalogi', 'Background'), default: '#FFFFFF' },
				{ key: 'text', label: t('opencatalogi', 'Text'), default: '#1B1B1B' },
			],
		}
	},
	computed: {
		/** The resolved object-context bag from inject (either shape) or {}. */
		ctx() {
			const inj = this.cnObjectContext && (this.cnObjectContext.value || this.cnObjectContext)
			const holder = this.cnDetailObjectContext && this.cnDetailObjectContext.value
			return inj || holder || {}
		},
		/** The current theme object, when the detail page has loaded one. */
		theme() {
			return (this.ctx && this.ctx.object) || {}
		},
		/**
		 * Colour-picker declarations. Guarded so CnThemePreview never
		 * receives `undefined` — falls back to a built-in default set
		 * when the manifest content carries none.
		 */
		pickers() {
			const configured = this.content && Array.isArray(this.content.pickers) ? this.content.pickers : []
			return configured.length > 0 ? configured : this.defaultPickers
		},
		/** Reset-button defaults map, derived from whichever picker set is active. */
		defaults() {
			if (this.content && this.content.defaults && typeof this.content.defaults === 'object') {
				return this.content.defaults
			}
			return this.pickers.reduce((acc, p) => {
				if (p.default !== undefined) acc[p.key] = p.default
				return acc
			}, {})
		},
		/** Initial colour map, guarded to always be a plain object. */
		value() {
			return (this.content && typeof this.content.value === 'object' && this.content.value) || {}
		},
		/** Sample-preview title — prefers the loaded theme's own title. */
		sampleTitle() {
			return this.theme.title || this.content.title || t('opencatalogi', 'Theme preview')
		},
		/** Sample-preview body text — prefers the loaded theme's summary/description. */
		sampleBodyText() {
			return this.theme.summary || this.theme.description || this.content.description
				|| t('opencatalogi', 'This is how publications with this theme look.')
		},
	},
	methods: { t },
}
</script>
