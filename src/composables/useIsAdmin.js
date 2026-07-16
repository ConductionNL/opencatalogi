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

/** @spec openspec/specs/admin-settings/spec.md */
export function useIsAdmin() {
	if (!loaded.value) load()
	return { isAdmin, loaded }
}
