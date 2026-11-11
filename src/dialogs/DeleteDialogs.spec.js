/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the three confirm-and-delete dialogs that read
 * the object store's state for one object type: DeleteCategoryDialog (the
 * active category), DeleteMultipleCategoriesDialog and
 * DeleteMultipleThemesDialog (the selected objects). It pins what each
 * renders while closed, asking, deleting, after a success and after an
 * error, and what pressing Delete does.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { h } from 'vue'
import DeleteCategoryDialog from './category/DeleteCategoryDialog.vue'
import DeleteMultipleCategoriesDialog from './category/DeleteMultipleCategoriesDialog.vue'
import DeleteMultipleThemesDialog from './theme/DeleteMultipleThemesDialog.vue'
import { navigationStore, objectStore } from '../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]),
}))

// Renders the dialog's name, its content and its actions, so the snapshot
// shows every slot the dialog fills.
const NcDialog = {
	name: 'NcDialog',
	props: { name: { type: String, default: '' }, canClose: { type: Boolean, default: true } },
	render() {
		return h('section', { class: 'nc-dialog-stub', 'data-name': this.name, 'data-can-close': String(this.canClose) }, [
			this.$slots.default?.(),
			h('footer', this.$slots.actions?.()),
		])
	},
}

const DIALOGS = [
	{ label: 'DeleteCategoryDialog', component: DeleteCategoryDialog, dialog: 'deleteCategory', type: 'category' },
	{ label: 'DeleteMultipleCategoriesDialog', component: DeleteMultipleCategoriesDialog, dialog: 'deleteMultipleCategories', type: 'category' },
	{ label: 'DeleteMultipleThemesDialog', component: DeleteMultipleThemesDialog, dialog: 'deleteMultipleThemes', type: 'theme' },
]

/**
 * Mount a dialog with the global t() the templates call.
 *
 * @param {object} component The dialog.
 * @return {object} The wrapper.
 */
function mountDialog(component) {
	return mount(component, {
		global: {
			stubs: { NcDialog },
			mocks: { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]) },
		},
	})
}

beforeEach(() => {
	jest.useRealTimers()
	navigationStore.setDialog(false)
	objectStore.setState('category', { success: null, error: null })
	objectStore.setState('theme', { success: null, error: null })
	objectStore.setLoading('category', false)
	objectStore.setLoading('theme', false)
	objectStore.activeObjects = { ...objectStore.activeObjects, category: { id: 'c-1', name: 'Wonen' } }
	objectStore.getSelectedObjects = jest.fn((type) => [{ id: `${type}-1` }, { id: `${type}-2` }])
	objectStore.deleteObject = jest.fn(() => Promise.resolve())
})

describe.each(DIALOGS)('$label', ({ component, dialog, type }) => {
	it('renders nothing while another dialog is open', () => {
		navigationStore.setDialog('somethingElse')
		expect(mountDialog(component).html()).toMatchSnapshot()
	})

	it('asks before it deletes', () => {
		navigationStore.setDialog(dialog)
		expect(mountDialog(component).html()).toMatchSnapshot()
	})

	it('shows that it is deleting', () => {
		navigationStore.setDialog(dialog)
		objectStore.setLoading(type, true)
		expect(mountDialog(component).html()).toMatchSnapshot()
	})

	it('shows a success with only a close button', () => {
		navigationStore.setDialog(dialog)
		objectStore.setState(type, { success: true, error: null })
		expect(mountDialog(component).html()).toMatchSnapshot()
	})

	it('shows an error beside the question', () => {
		navigationStore.setDialog(dialog)
		objectStore.setState(type, { success: false, error: 'Boom' })
		expect(mountDialog(component).html()).toMatchSnapshot()
	})

	it('deletes, then clears the state and closes after two seconds', async () => {
		jest.useFakeTimers()
		navigationStore.setDialog(dialog)
		objectStore.setState(type, { success: true, error: 'left over' })
		const wrapper = mountDialog(component)
		objectStore.setState(type, { success: null, error: null })
		await wrapper.vm.$nextTick()
		const buttons = wrapper.findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		await flushPromises()
		expect(objectStore.deleteObject.mock.calls).toMatchSnapshot()
		objectStore.setState(type, { success: true, error: 'kept' })
		jest.advanceTimersByTime(1999)
		expect([navigationStore.dialog, objectStore.getState(type)]).toMatchSnapshot()
		jest.advanceTimersByTime(1)
		expect([navigationStore.dialog, objectStore.getState(type)]).toMatchSnapshot()
	})

	it('does nothing without anything to delete', async () => {
		navigationStore.setDialog(dialog)
		objectStore.activeObjects = { ...objectStore.activeObjects, category: null }
		objectStore.getSelectedObjects = jest.fn(() => [])
		const buttons = mountDialog(component).findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		expect(objectStore.deleteObject).not.toHaveBeenCalled()
	})
})
