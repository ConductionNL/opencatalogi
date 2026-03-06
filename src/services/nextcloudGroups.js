/**
 * Nextcloud Groups Service
 *
 * Service for fetching and managing Nextcloud groups from the Nextcloud API.
 * This service provides access to the actual groups available on the Nextcloud instance.
 *
 * @module Services
 * @package
 * @author OpenCatalogi Development Team
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 *
 * @description
 * This service integrates with Nextcloud's OCS API to fetch available groups.
 * The OCS API endpoint used is: /ocs/v1.php/cloud/groups
 *
 * @example
 * // Basic usage
 * import { getNextcloudGroups } from './services/nextcloudGroups.js'
 *
 * const groups = await getNextcloudGroups()
 * console.log('Available groups:', groups)
 *
 * @example
 * // Force refresh groups
 * import { clearGroupsCache, getNextcloudGroups } from './services/nextcloudGroups.js'
 *
 * clearGroupsCache()
 * const freshGroups = await getNextcloudGroups()
 *
 * @example
 * // Check user login status and hide menu items accordingly
 * import { isUserLoggedIn, getCurrentUserGroups } from './services/nextcloudGroups.js'
 *
 * const isLoggedIn = isUserLoggedIn()
 * const userGroups = await getCurrentUserGroups()
 *
 * // Filter menu items based on login status and hideAfterLogin property
 * const visibleMenuItems = menuItems.filter(item => {
 *   if (item.hideAfterLogin && isLoggedIn) {
 *     return false // Hide item if user is logged in and item should be hidden
 *   }
 *   return true
 * })
 *
 * @see {@link https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/index.html}
 * @see {@link https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html}
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch all available groups from the Nextcloud instance
 *
 * @return {Promise<Array<{label: string, value: string}>>} Array of group objects with label and value
 */
export async function fetchNextcloudGroups() {
	try {
		// Use the working endpoint from Open Registers implementation
		const workingEndpoint = '/ocs/v1.php/cloud/groups?format=json'

		const response = await axios.get(generateUrl(workingEndpoint))

		if (response.data && response.data.ocs && response.data.ocs.data && response.data.ocs.data.groups) {
			// Transform the groups into the format expected by the dropdown
			return response.data.ocs.data.groups.map(group => ({
				label: group,
				value: group,
			}))
		}

		return []
	} catch (error) {
		console.warn('Failed to fetch groups from Nextcloud API:', error.message)
		return []
	}
}

/**
 * Fetch groups with caching to avoid repeated API calls
 *
 * This function implements a caching mechanism to avoid making repeated API calls
 * to the Nextcloud groups endpoint. The cache is valid for 5 minutes.
 *
 * @return {Promise<Array<{label: string, value: string}>>} Array of group objects
 *
 * @description
 * The function first checks if there are cached groups that are still valid.
 * If no valid cache exists, it fetches fresh groups from the Nextcloud API.
 * If the API call fails, it returns cached groups if available, or fallback groups.
 *
 * @example
 * // Get groups (will use cache if available)
 * const groups = await getNextcloudGroups()
 *
 * // Force refresh by clearing cache first
 * clearGroupsCache()
 * const freshGroups = await getNextcloudGroups()
 */
let cachedGroups = null
let cacheTimestamp = null
const CACHE_DURATION = 5 * 60 * 1000 // 5 minutes

export async function getNextcloudGroups() {
	const now = Date.now()

	// Return cached groups if they're still valid
	if (cachedGroups && cacheTimestamp && (now - cacheTimestamp) < CACHE_DURATION) {
		return cachedGroups
	}

	// Fetch fresh groups and update cache
	try {
		cachedGroups = await fetchNextcloudGroups()
		cacheTimestamp = now

		// If we got groups from the API, return them
		if (cachedGroups && cachedGroups.length > 0) {
			return cachedGroups
		}

		// If no groups were fetched, use fallback groups
		cachedGroups = [
			{ label: 'All Users', value: 'all' },
			{ label: 'Administrators', value: 'admin' },
			{ label: 'Functioneel-beheerder', value: 'Functioneel-beheerder' },
			{ label: 'Gebruik-beheerder', value: 'Gebruik-beheerder' },
			{ label: 'Gebruik-raadpleger', value: 'Gebruik-raadpleger' },
			{ label: 'Organisatie-beheerder', value: 'Organisatie-beheerder' },
			{ label: 'VNG-raadpleger', value: 'VNG-raadpleger' },
			{ label: 'Editors', value: 'editors' },
			{ label: 'Viewers', value: 'viewers' },
			{ label: 'Staff', value: 'staff' },
			{ label: 'Managers', value: 'managers' },
		]
		cacheTimestamp = now
		return cachedGroups

	} catch (error) {
		// Return cached groups if available, even if expired
		if (cachedGroups && cachedGroups.length > 0) {
			return cachedGroups
		}

		// Return fallback groups if no cache available
		return [
			{ label: 'All Users', value: 'all' },
			{ label: 'Administrators', value: 'admin' },
			{ label: 'Functioneel-beheerder', value: 'Functioneel-beheerder' },
			{ label: 'Gebruik-beheerder', value: 'Gebruik-beheerder' },
			{ label: 'Gebruik-raadpleger', value: 'Gebruik-raadpleger' },
			{ label: 'Organisatie-beheerder', value: 'Organisatie-beheerder' },
			{ label: 'VNG-raadpleger', value: 'VNG-raadpleger' },
			{ label: 'Editors', value: 'editors' },
			{ label: 'Viewers', value: 'viewers' },
			{ label: 'Staff', value: 'staff' },
			{ label: 'Managers', value: 'managers' },
		]
	}
}

/**
 * Clear the groups cache to force a fresh fetch
 *
 * @return {void}
 */
export function clearGroupsCache() {
	cachedGroups = null
	cacheTimestamp = null
}

/**
 * Check if the current user is logged in
 *
 * @return {boolean} True if user is logged in, false otherwise
 */
export function isUserLoggedIn() {
	// Check if we have user information in the Nextcloud context
	// This is a basic check - in a real implementation you might want to use
	// Nextcloud's user context or session management
	try {
		// Check if we're in a Nextcloud context and have user info
		if (typeof OC !== 'undefined' && OC.getCurrentUser) {
			const currentUser = OC.getCurrentUser()
			return currentUser && currentUser.uid && currentUser.uid !== ''
		}

		// Fallback: check if we have any user-related data in localStorage
		// This is not secure but provides a basic indication
		const hasUserData = localStorage.getItem('nc_username')
			|| localStorage.getItem('opencatalogi_user')
			|| sessionStorage.getItem('nc_username')

		return !!hasUserData
	} catch (error) {
		return false
	}
}

/**
 * Get the current user's groups
 *
 * @return {Promise<Array<string>>} Array of group names the current user belongs to
 */
export async function getCurrentUserGroups() {
	try {
		// Try to get user groups from Nextcloud API
		const response = await axios.get(generateUrl('/ocs/v1.php/cloud/users/current'))

		if (response.data && response.data.ocs && response.data.ocs.data) {
			const userData = response.data.ocs.data
			return userData.groups || []
		}

		return []
	} catch (error) {
		console.error('Error fetching current user groups:', error)
		return []
	}
}
