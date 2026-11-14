/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the two dialogs that read OpenRegister objects
 * before they act: CaseTypeResyncDialog (find the case type, read its diff)
 * and RetentionDecisionDialog (read the publications not handed over, and
 * word the decision for one or more of them).
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import CaseTypeResyncDialog from './caseType/CaseTypeResyncDialog.vue'
import RetentionDecisionDialog from './retention/RetentionDecisionDialog.vue'
import { navigationStore } from '../store/store.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => ({ service_catalogue_register: 'sc-reg', case_type_definition_schema: 'ctd', publication_register: 'pub-reg' })[key] ?? fallback,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]),
}))

const STUBS = {
	NcDialog: true,
	NcButton: true,
	NcCheckboxRadioSwitch: true,
	NcLoadingIcon: true,
	NcNoteCard: true,
	NcTextArea: true,
	NcTextField: true,
}

/**
 * Mount a dialog with the given dialog properties and let it load.
 *
 * @param {object} component The dialog.
 * @param {object} properties The dialog properties.
 * @return {Promise<object>} The wrapper.
 */
async function open(component, properties) {
	navigationStore.dialogProperties = properties
	const wrapper = mount(component, { global: { stubs: STUBS } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('CaseTypeResyncDialog.load', () => {
	/**
	 * What load() requested and left behind.
	 *
	 * @param {object} properties The dialog properties.
	 * @return {Promise<object>} The trace.
	 */
	async function trace(properties) {
		const vm = (await open(CaseTypeResyncDialog, properties)).vm
		return { gets: axios.get.mock.calls, state: vm.state, error: vm.error, definition: vm.definition, changes: vm.changes, choices: vm.choices, summary: vm.summary }
	}

	it('stops without a case type', async () => {
		expect(await trace({ caseType: '   ' })).toMatchSnapshot()
		expect(await trace({})).toMatchSnapshot()
	})

	it('stops when no definition is found', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [] } })
		expect(await trace({ caseType: ' ct-1 ' })).toMatchSnapshot()
		axios.get.mockResolvedValueOnce({ data: { results: [{ title: 'no id' }] } })
		expect(await trace({ caseType: 'ct-1' })).toMatchSnapshot()
	})

	it('reads the diff and keeps locally changed properties', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { results: [{ id: 'def-1', title: 'Vergunning' }] } })
			.mockResolvedValueOnce({ data: { changes: [{ property: 'a', locallyChanged: true }, { property: 'b' }] } })
		expect(await trace({ caseType: 'ct-1', entryTitle: 'E' })).toMatchSnapshot()
	})

	it('reads a diff without changes, and fails with the server message', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { results: [{ id: 'def-1', identifier: 'ct-1' }] } })
			.mockResolvedValueOnce({ data: { changes: 'none' } })
		expect(await trace({ caseType: 'ct-1' })).toMatchSnapshot()
		axios.get.mockRejectedValueOnce({ response: { data: { message: 'register down' } } })
		expect(await trace({ caseType: 'ct-1' })).toMatchSnapshot()
		axios.get
			.mockResolvedValueOnce({ data: { results: [{ id: 'def-1' }] } })
			.mockRejectedValueOnce(new Error('x'))
		expect(await trace({ caseType: 'ct-1' })).toMatchSnapshot()
	})
})

describe('RetentionDecisionDialog', () => {
	it('reads only the publications it was not handed, dropping unreadable ones', async () => {
		axios.get.mockImplementation((url) => (url.endsWith('/p3') ? Promise.reject(new Error('gone')) : Promise.resolve({ data: { id: url.split('/').pop(), read: true } })))
		const vm = (await open(RetentionDecisionDialog, { decision: 'archive', ids: ['p1', 2, 'p3', 'p4'], publications: [{ id: 'p1', title: 'given' }] })).vm
		expect({ gets: axios.get.mock.calls, publications: vm.publications, loading: vm.loading }).toMatchSnapshot()
	})

	it('reads nothing without ids, and ignores handed publications that are not a list', async () => {
		const vm = (await open(RetentionDecisionDialog, { publications: 'x' })).vm
		expect({ gets: axios.get.mock.calls, publications: vm.publications, loading: vm.loading, decision: vm.decision }).toMatchSnapshot()
	})

	it('words every decision for one and for several publications', async () => {
		const texts = {}
		for (const decision of ['extend', 'depublish', 'archive', 'unknown']) {
			for (const count of [0, 1, 3]) {
				const publications = Array.from({ length: count }, (_, i) => ({ id: 'p' + i }))
				const vm = (await open(RetentionDecisionDialog, { decision, publications })).vm
				texts[decision + ' ' + count] = [vm.title, vm.intro, vm.doneText]
			}
		}
		expect(texts).toMatchSnapshot()
	})
})
