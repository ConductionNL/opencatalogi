/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behaviour test for ConfirmObjectActionDialog: which of its slots, note
 * cards and buttons it shows for the object store's state of one object type
 * (asking, loading, success, failure), and what its buttons do.
 */

import { mount } from '@vue/test-utils'
import { h, markRaw } from 'vue'
import ConfirmObjectActionDialog from './ConfirmObjectActionDialog.vue'
import { navigationStore, objectStore } from '../../store/store.js'

// The object store imports @nextcloud/auth, whose ESM-only dependency Jest
// cannot parse; the dialog never reaches it.
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))

// Renders the dialog's name, its content and its actions.
const NcDialog = {
	name: 'NcDialog',
	props: { name: { type: String, default: '' } },
	render() {
		return h('section', { class: 'dialog', 'data-name': this.name }, [
			h('main', this.$slots.default?.()),
			h('footer', this.$slots.actions?.()),
		])
	},
}

// Renders a button with its variant and icon name, forwarding clicks.
const NcButton = {
	name: 'NcButton',
	props: {
		disabled: { type: Boolean, default: false },
		icon: { type: String, default: '' },
		variant: { type: String, default: 'secondary' },
	},
	render() {
		return h('button', { disabled: this.disabled, 'data-icon': this.icon, 'data-variant': this.variant }, [
			this.$slots.icon?.(),
			this.$slots.default?.(),
		])
	},
}

const ActionIcon = markRaw({
	name: 'ActionIcon',
	props: { size: { type: Number, default: 0 } },
	render() {
		return h('i', { class: 'action-icon', 'data-size': String(this.size) })
	},
})

const PROPS = {
	dialog: 'copyObject',
	objectType: 'theme',
	title: 'Copy theme',
	actionLabel: 'Copy',
	actionIcon: ActionIcon,
	actionIconName: 'icon-copy',
	actionVariant: 'primary',
}

/**
 * Mount the dialog with every slot filled by its own name.
 *
 * @param {object} props Props to override.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(ConfirmObjectActionDialog, {
		props: { ...PROPS, ...props },
		slots: {
			success: 'SUCCESS-SLOT',
			failure: 'FAILURE-SLOT',
			loading: 'LOADING-SLOT',
			question: 'QUESTION-SLOT',
		},
		global: {
			stubs: { NcDialog, NcButton },
			mocks: { t: (app, text) => `[${app}] ${text}` },
		},
	})
}

/**
 * The texts of the dialog's footer buttons.
 *
 * @param {object} wrapper The dialog.
 * @return {Array<string>} The button texts, in order.
 */
function buttonTexts(wrapper) {
	return wrapper.findAll('footer button').map((button) => button.text())
}

beforeEach(() => {
	navigationStore.setDialog('copyObject')
	objectStore.setState('theme', { success: null, error: null })
	objectStore.setLoading('theme', false)
})

afterEach(() => {
	jest.restoreAllMocks()
	navigationStore.setDialog(false)
})

describe('visibility', () => {
	it('renders nothing while another dialog is open', () => {
		navigationStore.setDialog('deleteObject')
		expect(mountDialog().find('section').exists()).toBe(false)
	})

	it('renders the dialog named by its title while its own dialog is open', () => {
		const dialog = mountDialog().find('section')
		expect(dialog.exists()).toBe(true)
		expect(dialog.attributes('data-name')).toBe('Copy theme')
	})
})

describe('asking', () => {
	it('shows only the question, with cancel and the action', () => {
		const wrapper = mountDialog()
		expect(wrapper.find('main').text()).toBe('QUESTION-SLOT')
		expect(wrapper.findAll('.nc-note-card-stub')).toHaveLength(0)
		expect(wrapper.find('.loading-status').exists()).toBe(false)
		expect(buttonTexts(wrapper)).toEqual(['[opencatalogi] Cancel', 'Copy'])
	})

	it('dresses the action button from its props', () => {
		const action = mountDialog().findAll('footer button')[1]
		expect(action.attributes('data-icon')).toBe('icon-copy')
		expect(action.attributes('data-variant')).toBe('primary')
		expect(action.attributes('disabled')).toBeUndefined()
		expect(action.find('.action-icon').attributes('data-size')).toBe('20')
	})

	it('emits confirm when the action is pressed, and does not close', async () => {
		const setDialog = jest.spyOn(navigationStore, 'setDialog')
		const wrapper = mountDialog()
		await wrapper.findAll('footer button')[1].trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[]])
		expect(setDialog).not.toHaveBeenCalled()
	})

	it('closes the dialog when cancel is pressed, without emitting confirm', async () => {
		const setDialog = jest.spyOn(navigationStore, 'setDialog')
		const wrapper = mountDialog()
		await wrapper.findAll('footer button')[0].trigger('click')
		expect(setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('reads the state of its own object type only', () => {
		objectStore.setState('catalog', { success: true, error: 'other type' })
		objectStore.setLoading('catalog', true)
		const wrapper = mountDialog()
		expect(wrapper.find('main').text()).toBe('QUESTION-SLOT')
		expect(buttonTexts(wrapper)).toEqual(['[opencatalogi] Cancel', 'Copy'])
		objectStore.setState('catalog', { success: null, error: null })
		objectStore.setLoading('catalog', false)
	})
})

describe('loading', () => {
	it('shows the loading slot beside a spinner and only a close button', () => {
		objectStore.setLoading('theme', true)
		const wrapper = mountDialog()
		const status = wrapper.find('.loading-status')
		expect(status.exists()).toBe(true)
		expect(status.text()).toBe('LOADING-SLOT')
		expect(status.find('.nc-loading-icon-stub').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('QUESTION-SLOT')
		expect(buttonTexts(wrapper)).toEqual(['[opencatalogi] Close'])
	})
})

describe('success', () => {
	it('shows the success slot in a success card and only a close button', () => {
		objectStore.setState('theme', { success: true, error: null })
		const wrapper = mountDialog()
		const cards = wrapper.findAll('.nc-note-card-stub')
		expect(cards.map((card) => [card.classes(), card.text()])).toEqual([
			[['nc-note-card-stub', 'nc-note-card-stub--success'], 'SUCCESS-SLOT'],
		])
		expect(wrapper.text()).not.toContain('QUESTION-SLOT')
		expect(buttonTexts(wrapper)).toEqual(['[opencatalogi] Close'])
	})

	it('closes the dialog when close is pressed', async () => {
		objectStore.setState('theme', { success: true, error: null })
		const setDialog = jest.spyOn(navigationStore, 'setDialog')
		const wrapper = mountDialog()
		await wrapper.find('footer button').trigger('click')
		expect(setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('also shows an error the store kept beside the success', () => {
		objectStore.setState('theme', { success: true, error: 'Partly failed' })
		const cards = mountDialog().findAll('.nc-note-card-stub')
		expect(cards.map((card) => card.text())).toEqual(['SUCCESS-SLOT', 'Partly failed'])
	})
})

describe('failure', () => {
	// The store reads a false success back as null, so a failure is an error
	// with the question still open for another try.
	it('shows the failure slot and the error, and keeps asking', () => {
		objectStore.setState('theme', { success: false, error: 'Boom' })
		const wrapper = mountDialog()
		const cards = wrapper.findAll('.nc-note-card-stub')
		expect(cards.map((card) => [card.classes()[1], card.text()])).toEqual([
			['nc-note-card-stub--error', 'FAILURE-SLOT'],
			['nc-note-card-stub--error', 'Boom'],
		])
		expect(wrapper.text()).toContain('QUESTION-SLOT')
		expect(buttonTexts(wrapper)).toEqual(['[opencatalogi] Cancel', 'Copy'])
	})

	it('shows no cards for a failure without an error', () => {
		objectStore.setState('theme', { success: false, error: null })
		const wrapper = mountDialog()
		expect(wrapper.findAll('.nc-note-card-stub')).toHaveLength(0)
		expect(wrapper.find('main').text()).toBe('QUESTION-SLOT')
	})
})
