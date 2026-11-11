/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `UploadDropNotice.vue`: which result notes it shows,
 * when its prompt is disabled, and the event its add button sends.
 */

import { mount } from '@vue/test-utils'
import UploadDropNotice from './UploadDropNotice.vue'

/**
 * Mount the notice with the given props.
 *
 * @param {object} props - The props
 * @return {object} The wrapper
 */
function mountNotice(props) {
	return mount(UploadDropNotice, {
		props,
		global: { mocks: { t: (app, text) => text } },
	})
}

describe('UploadDropNotice', () => {
	it('shows no result notes before an upload', () => {
		const wrapper = mountNotice({ labelCount: 1 })
		expect(wrapper.text()).not.toContain('Files added successfully')
		expect(wrapper.text()).toContain('Drag a file or files here')
	})

	it('shows the success and the duplicate warning', () => {
		const text = mountNotice({ labelCount: 1, success: true, duplicateWarning: 'Already added' }).text()
		expect(text).toContain('Files added successfully')
		expect(text).toContain('Already added')
		expect(text).not.toContain('Something went wrong when adding files')
	})

	it('shows the error without a success', () => {
		const text = mountNotice({ labelCount: 1, success: false, error: 'Too big' }).text()
		expect(text).toContain('Something went wrong when adding files')
		expect(text).toContain('Too big')
	})

	it.each([
		[{ labelCount: 0 }, true],
		[{}, true],
		[{ labelCount: 1, loading: true }, true],
		[{ labelCount: 1, retryLoading: true }, true],
		[{ labelCount: 2 }, false],
	])('disables the prompt for %j: %s', (props, disabled) => {
		const wrapper = mountNotice(props)
		expect(wrapper.find('.filesListDragDropNoticeWrapper').classes('filesListDragDropNoticeWrapper--disabled')).toBe(disabled)
	})

	it('asks to open the file picker', async () => {
		const wrapper = mountNotice({ labelCount: 1 })
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('openFileUpload')).toHaveLength(1)
	})
})
