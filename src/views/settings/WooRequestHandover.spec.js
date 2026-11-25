/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
 */

import { mount } from '@vue/test-utils'
import WooRequestHandover, { dossiqEnabled } from './WooRequestHandover.vue'

jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
jest.mock('@nextcloud/vue', () => ({
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))

/**
 * Mount the notice with the given `OC` global.
 *
 * @param {object|undefined} oc The page's `OC`.
 * @return {object} The wrapper.
 */
function mountWith(oc) {
	globalThis.OC = oc
	return mount(WooRequestHandover, { global: { mocks: { t: (app, text) => text } } })
}

afterEach(() => {
	delete globalThis.OC
})

describe('WooRequestHandover', () => {
	it('says Woo requests are dossiq\'s and links to the app store when dossiq is not enabled', () => {
		const wrapper = mountWith({ appswebroots: { opencatalogi: '/apps/opencatalogi' } })

		expect(wrapper.find('[data-testid="woo-requests-dossiq-missing"]').text()).toContain('Woo requests are handled by dossiq, which is not installed.')
		const link = wrapper.find('[data-testid="woo-requests-dossiq-install"]')
		expect(link.attributes('href')).toBe('/index.php/settings/apps')
		expect(link.text()).toBe('Install dossiq from the app store')
	})

	it('shows nothing when dossiq is enabled', () => {
		const wrapper = mountWith({ appswebroots: { dossiq: '/apps/dossiq' } })

		expect(wrapper.find('[data-testid="woo-requests-dossiq-missing"]').exists()).toBe(false)
	})

	it('reads dossiq from the enabled apps only', () => {
		expect(dossiqEnabled({ appswebroots: { dossiq: '/custom_apps/dossiq' } })).toBe(true)
		expect(dossiqEnabled({ appswebroots: {} })).toBe(false)
		expect(dossiqEnabled(undefined)).toBe(false)
	})
})
