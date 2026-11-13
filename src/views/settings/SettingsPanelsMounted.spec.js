/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings panels as rendered: WooIndexConnection loads its rules and
 * registration, copies a rule and confirms a requested registration;
 * PubliccodeHarvest shows the harvest status and posts each action, then
 * reads the status again; UserSettings renders its one section and passes
 * the dialog's open state up. Only HTTP and the Nextcloud globals are mocked;
 * the @nextcloud/vue components are stubbed to render their slots.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PubliccodeHarvest from './PubliccodeHarvest.vue'
import UserSettings from './UserSettings.vue'
import WooIndexConnection from './WooIndexConnection.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (m, key) => vars[key]),
}))
jest.mock('@nextcloud/vue', () => {
	const { h: render } = require('vue')
	const slotted = (tag, cls) => ({
		inheritAttrs: false,
		props: ['type', 'variant', 'disabled', 'open', 'name', 'size'],
		emits: ['click', 'update:open'],
		render() {
			return render(
				tag,
				{
					class: cls,
					'data-type': this.type,
					disabled: this.disabled,
					'data-testid': this.$attrs['data-testid'],
					onClick: () => this.$emit('click'),
				},
				[this.name ? render('h4', this.name) : null, this.$slots.icon?.(), this.$slots.default?.()],
			)
		},
	})
	return {
		NcButton: slotted('button', 'nc-button'),
		NcNoteCard: slotted('div', 'nc-note-card'),
		NcLoadingIcon: slotted('span', 'nc-loading'),
		NcAppSettingsDialog: {
			props: ['open', 'name', 'showNavigation'],
			emits: ['update:open'],
			render() {
				return render('section', { class: 'nc-settings-dialog', 'data-open': String(this.open) }, [
					render('h2', this.name),
					render('button', { class: 'close', onClick: () => this.$emit('update:open', false) }),
					this.$slots.default?.(),
				])
			},
		},
		NcAppSettingsSection: slotted('div', 'nc-settings-section'),
	}
})

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key]))
const options = { global: { mocks: { t }, stubs: { CogIcon: true, CheckCircle: true, CloseCircle: true, InformationOutline: true, MinusCircle: true } } }

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('WooIndexConnection rendered', () => {
	const loaded = {
		registration: { status: 'requested', registeredAt: '2026-11-01', registeredUrl: '', answer: 'In behandeling' },
		rules: { nginx: 'location = /robots.txt {}', apache: 'RewriteRule ^robots\\.txt$' },
	}

	it('shows both root rules, the requested registration and the Woo-index answer', async () => {
		axios.get.mockResolvedValue({ data: loaded })
		const wrapper = mount(WooIndexConnection, options)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/woo/registration')
		expect(wrapper.find('[data-testid="woo-root-rule-nginx"]').text()).toBe('location = /robots.txt {}')
		expect(wrapper.find('[data-testid="woo-root-rule-apache"]').text()).toBe('RewriteRule ^robots\\.txt$')
		expect(wrapper.find('[data-testid="woo-registration-status"]').text()).toBe('Requested on 2026-11-01. Waiting for the Woo-index to confirm.')
		expect(wrapper.text()).toContain('Answer from the Woo-index: In behandeling')
		expect(wrapper.find('[data-testid="woo-registration-confirm"]').exists()).toBe(true)
	})

	it('copies a rule and says so on its button; a refused copy says to copy by hand', async () => {
		axios.get.mockResolvedValue({ data: loaded })
		const writeText = jest.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = mount(WooIndexConnection, options)
		await flushPromises()

		await wrapper.find('[data-testid="woo-root-rule-copy-apache"]').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('RewriteRule ^robots\\.txt$')
		expect(wrapper.find('[data-testid="woo-root-rule-copy-apache"]').text()).toBe('Copied')
		expect(wrapper.find('[data-testid="woo-root-rule-copy-nginx"]').text()).toBe('Copy')

		writeText.mockRejectedValue(new Error('denied'))
		await wrapper.find('[data-testid="woo-root-rule-copy-nginx"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('.nc-note-card[data-type="error"]').text()).toBe('Copying did not work. Select the rule and copy it by hand.')
	})

	it('marks a requested registration as registered', async () => {
		axios.get.mockResolvedValue({ data: loaded })
		axios.post.mockResolvedValue({ data: { registration: { status: 'registered', registeredAt: '2026-11-02', registeredUrl: 'https://woo.example.nl', answer: '' } } })
		const wrapper = mount(WooIndexConnection, options)
		await flushPromises()

		await wrapper.find('[data-testid="woo-registration-confirm"]').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/woo/registration/confirm')
		expect(wrapper.find('[data-testid="woo-registration-status"]').text()).toBe('Registered on 2026-11-02 for https://woo.example.nl.')
		expect(wrapper.find('[data-testid="woo-registration-confirm"]').exists()).toBe(false)
	})

	it('says when the connection could not be loaded, and starts as not registered', async () => {
		axios.get.mockRejectedValue(new Error('500'))
		const wrapper = mount(WooIndexConnection, options)
		await flushPromises()

		expect(wrapper.find('.nc-note-card[data-type="error"]').text()).toBe('The Woo-index connection could not be loaded.')
		expect(wrapper.find('[data-testid="woo-registration-status"]').text()).toBe('Not registered yet.')
	})
})

describe('PubliccodeHarvest rendered', () => {
	const ready = {
		integriq: true,
		source: { exists: true, enabled: true, slug: 'github-api', uuid: 'src-1' },
		shards: { expected: 4, present: 4 },
		flow: { imported: true, enabled: true, runAs: 'admin', cron: '0 * * * *' },
		lastRun: { failedSteps: 2 },
	}

	it('shows the status of a harvest that is set up and on', async () => {
		axios.get.mockResolvedValue({ data: ready })
		const wrapper = mount(PubliccodeHarvest, options)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/settings/publiccode-harvest')
		expect(wrapper.find('[data-testid="publiccode-harvest-source"]').text()).toContain('The GitHub source github-api is switched on.')
		expect(wrapper.find('[data-testid="publiccode-harvest-source-link"]').attributes('href')).toBe('/apps/integriq/sources/src-1')
		expect(wrapper.find('[data-testid="publiccode-harvest-flow"]').text()).toContain('The harvest is on. It runs as admin on schedule 0 * * * *.')
		expect(wrapper.find('[data-testid="publiccode-harvest-failed-steps"]').text()).toContain('2 step(s) of the last run failed')
		expect(wrapper.find('[data-testid="publiccode-harvest-enable"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="publiccode-harvest-needs-integriq"]').exists()).toBe(false)
	})

	it('posts an action and reads the status again; a refusal shows the server\'s reason', async () => {
		axios.get.mockResolvedValueOnce({ data: { ...ready, flow: { imported: true, enabled: false } } }).mockResolvedValue({ data: ready })
		axios.post.mockResolvedValueOnce({ data: {} })
		const wrapper = mount(PubliccodeHarvest, options)
		await flushPromises()
		expect(wrapper.find('[data-testid="publiccode-harvest-run"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="publiccode-harvest-enable"]').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/settings/publiccode-harvest/enable', {})
		expect(axios.get).toHaveBeenCalledTimes(2)

		await wrapper.find('[data-testid="publiccode-harvest-run"]').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/settings/publiccode-harvest/run', {})
		expect(axios.get).toHaveBeenCalledTimes(3)
	})

	it('shows the server\'s reason for a refused action, else a fixed sentence', async () => {
		const state = { busy: false, error: '', load: jest.fn(), t }

		axios.post.mockRejectedValueOnce({ response: { data: { error: 'The token is missing.' } } })
		await PubliccodeHarvest.methods.act.call(state, 'run')
		expect(state).toMatchObject({ busy: false, error: 'The token is missing.' })
		expect(state.load).toHaveBeenCalledTimes(1)

		axios.post.mockRejectedValueOnce(new Error('offline'))
		await PubliccodeHarvest.methods.act.call(state, 'setup', { owner: 'x' })
		expect(axios.post).toHaveBeenLastCalledWith('/apps/opencatalogi/api/settings/publiccode-harvest/setup', { owner: 'x' })
		expect(state.error).toBe('That did not work. Try again.')
	})

	it('says when integriq is missing, and when the status could not be loaded', async () => {
		axios.get.mockResolvedValueOnce({ data: { ...ready, integriq: false } })
		const wrapper = mount(PubliccodeHarvest, options)
		await flushPromises()
		expect(wrapper.find('[data-testid="publiccode-harvest-needs-integriq"]').exists()).toBe(true)

		axios.get.mockRejectedValueOnce(new Error('500'))
		const failed = mount(PubliccodeHarvest, options)
		await flushPromises()
		expect(failed.find('.nc-note-card[data-type="error"]').text()).toBe('The GitHub harvest status could not be loaded.')
	})
})

describe('UserSettings rendered', () => {
	it('renders its general section and passes closing up', async () => {
		const wrapper = mount(UserSettings, { ...options, props: { open: true } })

		expect(wrapper.find('.nc-settings-dialog').attributes('data-open')).toBe('true')
		expect(wrapper.text()).toContain('OpenCatalogi settings')
		expect(wrapper.text()).toContain('User preferences will appear here.')
		await wrapper.find('button.close').trigger('click')
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})

