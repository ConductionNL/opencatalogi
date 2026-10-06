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

const NcModal = {
	name: 'NcModal',
	emits: ['close'],
	render() {
		return h('div', { class: 'nc-modal-stub' }, this.$slots.default?.())
	},
}

const NcButton = {
	name: 'NcButton',
	props: {
		disabled: { type: Boolean, default: false },
		variant: { type: String, default: 'secondary' },
	},
	render() {
		return h('button', { class: 'nc-button-stub', disabled: this.disabled }, [
			this.$slots.icon?.(),
			this.$slots.default?.(),
		])
	},
}

// Single and multiple selection both travel through `modelValue`;
// tests drive a selection by emitting `update:modelValue` on this stub.
const NcSelect = {
	name: 'NcSelect',
	props: {
		modelValue: { type: [Array, String, Object, Number], default: null },
		options: { type: Array, default: () => [] },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
	render() {
		return h('div', { class: 'nc-select-stub' })
	},
}

const NcCheckboxRadioSwitch = {
	name: 'NcCheckboxRadioSwitch',
	props: {
		modelValue: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
	render() {
		return h(
			'div',
			{ class: 'nc-checkbox-radio-switch-stub' },
			this.$slots.default?.(),
		)
	},
}

const NcNoteCard = {
	name: 'NcNoteCard',
	props: {
		type: { type: String, default: 'info' },
	},
	render() {
		return h(
			'div',
			{ class: `nc-note-card-stub nc-note-card-stub--${this.type}` },
			this.$slots.default?.(),
		)
	},
}

const NcLoadingIcon = {
	name: 'NcLoadingIcon',
	render() {
		return h('span', { class: 'nc-loading-icon-stub' })
	},
}

module.exports = {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
}
