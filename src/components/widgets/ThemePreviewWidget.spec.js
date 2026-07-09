/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `ThemePreviewWidget.vue`.
 *
 * Regression coverage for the ThemeDetail crash: the manifest's `theme-preview`
 * widget entry carries no `content` (the Theme schema has no colour fields to
 * seed it from), so mounting the library's `CnThemePreview` there directly
 * threw `TypeError: this.pickers is not iterable` in `buildInitialModel()`
 * followed by `Cannot convert undefined or null to object` in the
 * `previewStyle` computed — the widget never rendered.
 */

import { mount } from '@vue/test-utils'
import ThemePreviewWidget from './ThemePreviewWidget.vue'

describe('ThemePreviewWidget', () => {
	it('mounts without throwing when the manifest supplies no content at all', () => {
		expect(() => mount(ThemePreviewWidget)).not.toThrow()
	})

	it('falls back to a non-empty, valid default pickers set when content.pickers is missing', () => {
		const wrapper = mount(ThemePreviewWidget)

		expect(wrapper.vm.pickers.length).toBeGreaterThan(0)
		wrapper.vm.pickers.forEach((p) => {
			expect(typeof p.key).toBe('string')
			expect(typeof p.label).toBe('string')
		})
		// The underlying CnThemePreview stub mounted successfully with the
		// fallback pickers — i.e. it never received an undefined/empty array.
		expect(wrapper.find('.cn-theme-preview-stub').exists()).toBe(true)
	})

	it('falls back to defaults even when content.pickers is explicitly an empty array', () => {
		const wrapper = mount(ThemePreviewWidget, {
			propsData: { content: { pickers: [] } },
		})

		expect(wrapper.vm.pickers.length).toBeGreaterThan(0)
		expect(wrapper.find('.cn-theme-preview-stub').exists()).toBe(true)
	})

	it('uses the manifest-supplied pickers/defaults/value when content provides them', () => {
		const content = {
			pickers: [{ key: 'accent', label: 'Accent', default: '#ff0000' }],
			defaults: { accent: '#ff0000' },
			value: { accent: '#00ff00' },
		}
		const wrapper = mount(ThemePreviewWidget, { propsData: { content } })

		expect(wrapper.vm.pickers).toEqual(content.pickers)
		expect(wrapper.vm.defaults).toEqual(content.defaults)
		expect(wrapper.vm.value).toEqual(content.value)
	})

	it('prefers the loaded theme object title/summary over generic sample text', () => {
		const wrapper = mount(ThemePreviewWidget, {
			provide: {
				cnObjectContext: { value: { object: { title: 'Housing', summary: 'Municipal housing theme' } } },
			},
		})

		expect(wrapper.vm.sampleTitle).toBe('Housing')
		expect(wrapper.vm.sampleBodyText).toBe('Municipal housing theme')
	})

	it('never leaves value/defaults as non-object when content carries garbage', () => {
		const wrapper = mount(ThemePreviewWidget, {
			propsData: { content: { value: null, defaults: 'not-an-object' } },
		})

		expect(typeof wrapper.vm.value).toBe('object')
		expect(typeof wrapper.vm.defaults).toBe('object')
	})
})
