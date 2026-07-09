/**
 * Jest mock for any `vue-material-design-icons/*.vue` import.
 *
 * These are raw, untranspiled `.vue` SFCs shipped inside `node_modules`;
 * Jest's transform pipeline ignores `node_modules` by default, so importing
 * one directly throws a syntax error on the raw `<template>` tag. Icon
 * components are purely decorative for component specs, so a single generic
 * stub (icon-agnostic — the `size`/`title` props are shared across the whole
 * icon set) covers every import path via the `moduleNameMapper` regex.
 */
module.exports = {
	name: 'MaterialDesignIconStub',
	props: {
		size: { type: [Number, String], default: 24 },
		title: { type: String, default: '' },
	},
	render(h) {
		return h('span', { class: 'material-design-icon-stub' })
	},
}
