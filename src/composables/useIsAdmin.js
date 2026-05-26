import { ref } from 'vue'
import { getCurrentUserGroups } from '../services/nextcloudGroups.js'

const isAdmin = ref(false)
const loaded = ref(false)
let pending = null

function load() {
	if (pending) return pending
	pending = getCurrentUserGroups()
		.then((groups) => {
			isAdmin.value = Array.isArray(groups) && groups.includes('admin')
		})
		.catch(() => {
			isAdmin.value = false
		})
		.finally(() => {
			loaded.value = true
		})
	return pending
}

/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md#task-1 */
export function useIsAdmin() {
	if (!loaded.value) load()
	return { isAdmin, loaded }
}
