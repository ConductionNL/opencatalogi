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

/**
 * A container stub that renders only its default slot and swallows every prop.
 *
 * @param {string} name - The component name, so specs can find it by name.
 * @return {object} The stub component.
 */
function slotStub(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			return h('div', { class: `${name}-stub` }, this.$slots.default?.())
		},
	}
}

// Renders its options as a list labelled by `inputLabel` or
// `ariaLabelCombobox`, so specs can assert the option order a user would see.
const NcSelect = {
	name: 'NcSelect',
	props: {
		options: { type: Array, default: () => [] },
		inputLabel: { type: String, default: '' },
		ariaLabelCombobox: { type: String, default: '' },
		modelValue: { type: [Object, Array, String, Number], default: null },
	},
	emits: ['update:modelValue'],
	render() {
		return h(
			'ul',
			{
				class: 'nc-select-stub',
				'aria-label': this.inputLabel || this.ariaLabelCombobox,
			},
			this.options.map((option) => h('li', option?.label)),
		)
	},
}

module.exports = {
	NcActionButton: slotStub('NcActionButton'),
	NcActions: slotStub('NcActions'),
	NcButton: slotStub('NcButton'),
	NcCheckboxRadioSwitch: slotStub('NcCheckboxRadioSwitch'),
	NcCounterBubble: slotStub('NcCounterBubble'),
	NcDialog: slotStub('NcDialog'),
	NcEmptyContent,
	NcLoadingIcon: slotStub('NcLoadingIcon'),
	NcModal: slotStub('NcModal'),
	NcNoteCard: slotStub('NcNoteCard'),
	NcSelect,
	NcTextField: slotStub('NcTextField'),
}
