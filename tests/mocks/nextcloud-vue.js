/**
 * Jest mock for `@nextcloud/vue`.
 *
 * The real package ships an ESM/CJS dist bundled with inlined CSS chunks
 * (e.g. `NcActionButton-*.css`) that Jest's default transform can't parse
 * without a cross-cutting `transformIgnorePatterns` rewrite. Component specs
 * that mount a widget using a handful of NC components don't need the real
 * ones — this stub provides minimal, presentational replacements.
 *
 * Extend this file (rather than reaching for transformIgnorePatterns) as
 * more component specs need additional NC components stubbed.
 */
const { h } = require('vue')

const NcEmptyContent = {
	name: 'NcEmptyContent',
	props: {
		name: { type: String, default: '' },
	},
	// ⚠️ Two Vue 3 breaks in one line. `render()` receives no `h` argument (it is
	// imported from 'vue'), and `$slots.*` are now FUNCTIONS, not vnode arrays —
	// passing them raw renders nothing and warns about an invalid vnode type.
	render() {
		return h('div', { class: 'nc-empty-content-stub' }, [
			this.name,
			this.$slots.icon?.(),
			this.$slots.default?.(),
		])
	},
}

module.exports = {
	NcEmptyContent,
}
