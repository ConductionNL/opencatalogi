/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the set-up steps of `MergeObject.vue` (handed-over target,
 * target search, source files, file type labels) and `MigrationObject.vue`
 * (register change, next step, schema properties).
 */

import axios from '@nextcloud/axios'
import MergeObject from './MergeObject.vue'
import MigrationObject from './MigrationObject.vue'
import { navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { getTransferData: jest.fn() },
	objectStore: {},
	catalogStore: {},
}))

/**
 * A merge dialog state with its methods, the given fields and spies.
 *
 * @param {object} fields State to set.
 * @return {object} The state.
 */
function mergeState(fields = {}) {
	return {
		...MergeObject.methods,
		closeModal: jest.fn(),
		loadSourceData: jest.fn(),
		initializeMergeData: jest.fn(),
		searchObjects: jest.fn(),
		step: 1,
		selectedTargetObject: null,
		...fields,
	}
}

describe('MergeObject initializeMerge', () => {
	it('closes without a source', () => {
		navigationStore.getTransferData.mockReturnValue({})
		const state = mergeState({ sourceObject: null })
		MergeObject.methods.initializeMerge.call(state)

		expect(state.closeModal).toHaveBeenCalled()
		expect(state.loadSourceData).not.toHaveBeenCalled()
		expect(state.searchObjects).not.toHaveBeenCalled()
		expect(state.mergeSource).toBeNull()
	})

	it('takes a handed-over target and starts at step 2 when asked', () => {
		navigationStore.getTransferData.mockReturnValue({ mergeSource: 's', mergeTarget: 't', startStep: 2 })
		const state = mergeState({ sourceObject: { id: 's' } })
		MergeObject.methods.initializeMerge.call(state)

		expect(state.mergeSource).toBe('s')
		expect(state.selectedTargetObject).toBe('t')
		expect(state.step).toBe(2)
		expect(state.initializeMergeData).toHaveBeenCalled()
		expect(state.loadSourceData.mock.invocationCallOrder[0]).toBeLessThan(state.initializeMergeData.mock.invocationCallOrder[0])
		expect(state.searchObjects).toHaveBeenCalled()
	})

	it('takes a handed-over target and stays at step 1 otherwise', () => {
		navigationStore.getTransferData.mockReturnValue({ mergeTarget: 't', startStep: 1 })
		const state = mergeState({ sourceObject: { id: 's' } })
		MergeObject.methods.initializeMerge.call(state)

		expect(state.selectedTargetObject).toBe('t')
		expect(state.step).toBe(1)
		expect(state.initializeMergeData).not.toHaveBeenCalled()
		expect(state.searchObjects).toHaveBeenCalled()
	})

	it('reads an absent transfer as empty', () => {
		navigationStore.getTransferData.mockReturnValue(undefined)
		const state = mergeState({ sourceObject: { id: 's' } })
		MergeObject.methods.initializeMerge.call(state)

		expect(state.selectedTargetObject).toBeNull()
		expect(state.searchObjects).toHaveBeenCalled()
	})
})

describe('MergeObject searchObjects', () => {
	/**
	 * Run searchObjects for the given source pair.
	 *
	 * @param {object} sourcePair The register and schema.
	 * @return {Promise<object>} The state afterwards.
	 */
	async function search(sourcePair) {
		const state = {
			...MergeObject.methods,
			sourcePair,
			sourceObject: { '@self': { id: 's' } },
			searchTerm: 'park',
			loading: false,
			availableObjects: 'old',
		}
		await MergeObject.methods.searchObjects.call(state)
		return state
	}

	beforeEach(() => axios.get.mockReset())

	it('does nothing without a register or schema', async () => {
		const state = await search({ register: 1, schema: undefined })

		expect(axios.get).not.toHaveBeenCalled()
		expect(state.availableObjects).toBe('old')
	})

	it('lists the pair objects except the source', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ '@self': { id: 's' } }, { '@self': { id: 't' } }] } })
		const state = await search({ register: 19, schema: 173 })

		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/19/173', { params: { _search: 'park' } })
		expect(state.availableObjects).toEqual([{ '@self': { id: 't' } }])
		expect(state.loading).toBe(false)
	})

	it('lists nothing when the search fails', async () => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
		axios.get.mockRejectedValue(new Error('down'))
		const state = await search({ register: 19, schema: 173 })

		expect(state.availableObjects).toEqual([])
		expect(state.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Error searching objects:', expect.any(Error))
	})
})

describe('MergeObject loadSourceData and getFileType', () => {
	it.each([
		[{ attachments: [{ id: 1 }] }, [{ id: 1 }]],
		[{ attachments: 'x' }, []],
		[{}, []],
	])('takes the source files of %j', async (sourceObject, files) => {
		const state = { sourceObject, sourceFiles: 'old', sourceRelations: 'old' }
		await MergeObject.methods.loadSourceData.call(state)

		expect(state.sourceFiles).toEqual(files)
		expect(state.sourceRelations).toEqual([])
	})

	it('leaves the lists alone without a source', async () => {
		const state = { sourceObject: null, sourceFiles: 'old', sourceRelations: 'old' }
		await MergeObject.methods.loadSourceData.call(state)

		expect(state).toMatchObject({ sourceFiles: 'old', sourceRelations: 'old' })
	})

	it.each([
		[undefined, 'Unknown'],
		['', 'Unknown'],
		['a.PDF', 'PDF'],
		['a.docx', 'Word'],
		['a.xls', 'Excel'],
		['a.pptx', 'PowerPoint'],
		['a.txt', 'Text'],
		['a.jpeg', 'Image'],
		['a.rar', 'Archive'],
		['a.odt', 'ODT'],
		['README', 'README'],
		['a.', 'Unknown'],
	])('labels %s as %s', (filename, label) => {
		expect(MergeObject.methods.getFileType(filename)).toBe(label)
	})
})

describe('MigrationObject set-up steps', () => {
	beforeEach(() => {
		globalThis.fetch = jest.fn()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('clears the schema choice without a register', async () => {
		const state = { ...MigrationObject.methods, targetRegister: null, availableSchemas: ['x'], targetSchema: 'y' }
		await MigrationObject.methods.onRegisterChange.call(state)

		expect(state).toMatchObject({ availableSchemas: [], targetSchema: null })
		expect(fetch).not.toHaveBeenCalled()
	})

	it.each([
		[{ results: [{ id: 1 }] }, [{ id: 1 }]],
		[{}, []],
	])('lists the register schemas from %j', async (body, schemas) => {
		fetch.mockResolvedValue({ json: async () => body })
		const state = { ...MigrationObject.methods, targetRegister: { id: 5 }, targetSchema: 'kept', loading: false }
		await MigrationObject.methods.onRegisterChange.call(state)

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/schemas?register=5')
		expect(state).toMatchObject({ availableSchemas: schemas, targetSchema: 'kept', loading: false })
	})

	it('lists no schemas when the request fails', async () => {
		fetch.mockRejectedValue(new Error('down'))
		const state = { ...MigrationObject.methods, targetRegister: { id: 5 }, loading: false }
		await MigrationObject.methods.onRegisterChange.call(state)

		expect(state).toMatchObject({ availableSchemas: [], loading: false })
		expect(console.error).toHaveBeenCalledWith('Error loading schemas:', expect.any(Error))
	})

	it.each([
		[1, { selectedObjects: [1] }, 2],
		[1, { selectedObjects: [] }, 1],
		[2, { targetRegister: {}, targetSchema: {} }, 3],
		[2, { targetRegister: {}, targetSchema: null }, 2],
		[2, { targetRegister: null, targetSchema: {} }, 2],
		[3, { selectedObjects: [1], targetRegister: {}, targetSchema: {} }, 3],
	])('nextStep from %i with %j goes to %i', (step, fields, expected) => {
		const state = { ...MigrationObject.methods, selectedObjects: [], step, ...fields }
		MigrationObject.methods.nextStep.call(state)

		expect(state.step).toBe(expected)
	})

	it('loads the source and target properties, then the mappings', async () => {
		fetch.mockResolvedValue({ json: async () => ({ id: 8, properties: { b: {} } }) })
		const order = []
		const state = {
			...MigrationObject.methods,
			sourceSchema: { properties: { a: {} } },
			targetSchema: { id: 8 },
			extractSchemaProperties: (schema) => Object.keys(schema.properties),
			initializePropertyMappings: () => order.push('init'),
			convertMappingToUI: () => order.push('ui'),
		}
		await MigrationObject.methods.loadSchemaProperties.call(state)

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/schemas/8')
		expect(state.sourceProperties).toEqual(['a'])
		expect(state.targetProperties).toEqual(['b'])
		expect(order).toEqual(['init', 'ui'])
	})

	it('keeps the source properties when the target schema fails to load', async () => {
		fetch.mockRejectedValue(new Error('down'))
		const state = {
			...MigrationObject.methods,
			sourceSchema: { properties: { a: {} } },
			targetSchema: { id: 8 },
			targetProperties: 'old',
			extractSchemaProperties: (schema) => Object.keys(schema.properties),
			initializePropertyMappings: jest.fn(),
		}
		await MigrationObject.methods.loadSchemaProperties.call(state)

		expect(state.sourceProperties).toEqual(['a'])
		expect(state.targetProperties).toBe('old')
		expect(state.initializePropertyMappings).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalledWith('Error loading schema properties:', expect.any(Error))
	})

	it('loads nothing without both schemas', async () => {
		const state = { ...MigrationObject.methods, sourceSchema: {}, targetSchema: null }
		await MigrationObject.methods.loadSchemaProperties.call(state)

		expect(fetch).not.toHaveBeenCalled()
		expect(state.sourceProperties).toBeUndefined()
	})
})
