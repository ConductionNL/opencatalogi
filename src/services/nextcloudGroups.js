/**
 * Nextcloud Groups Service
 * 
 * Service for fetching and managing Nextcloud groups from the Nextcloud API.
 * This service provides access to the actual groups available on the Nextcloud instance.
 * 
 * @module Services
 * @package OpenCatalogi
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
 * // Filter menu items based on login status and hideAfterInlog property
 * const visibleMenuItems = menuItems.filter(item => {
 *   if (item.hideAfterInlog && isLoggedIn) {
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
		// Try multiple possible Nextcloud API endpoints for groups
		const possibleEndpoints = [
			'/ocs/v1.php/cloud/groups',
			'/ocs/v2.php/cloud/groups',
			'/index.php/ocs/v1.php/cloud/groups',
			'/index.php/ocs/v2.php/cloud/groups',
			'/apps/opencatalogi/api/groups', // Custom endpoint if available
			'/ocs/v1.php/cloud/users/groups', // Alternative endpoint
			'/ocs/v1.php/cloud/users', // Users endpoint might include groups
			'/ocs/v1.php/cloud', // Root cloud endpoint
		]

		let groups = []
		let lastError = null

		for (const endpoint of possibleEndpoints) {
			try {
				const response = await axios.get(generateUrl(endpoint))
				
				if (response.data) {
					// Handle different response formats
					if (response.data.ocs && response.data.ocs.data && response.data.ocs.data.groups) {
						// Standard OCS format
						groups = response.data.ocs.data.groups
						break
					} else if (response.data.groups) {
						// Direct groups format
						groups = response.data.groups
						break
					} else if (Array.isArray(response.data)) {
						// Array format
						groups = response.data
						break
					} else if (response.data.ocs && response.data.ocs.data) {
						// Check if groups are in a different location
						const data = response.data.ocs.data
						if (data.users && Array.isArray(data.users)) {
							// Extract groups from users data
							const allGroups = new Set()
							data.users.forEach(user => {
								if (user.groups && Array.isArray(user.groups)) {
									user.groups.forEach(group => allGroups.add(group))
								}
							})
							if (allGroups.size > 0) {
								groups = Array.from(allGroups)
								break
							}
						}
					}
				}
			} catch (error) {
				lastError = error
				continue
			}
		}

		if (groups.length > 0) {
			// Transform the groups into the format expected by the dropdown
			return groups.map(group => ({
				label: group,
				value: group
			}))
		}

		return []
	} catch (error) {
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
			{ label: 'Users', value: 'users' },
			{ label: 'Guests', value: 'guests' },
			{ label: 'Editors', value: 'editors' },
			{ label: 'Viewers', value: 'viewers' },
			{ label: 'Moderators', value: 'moderators' },
			{ label: 'Content Managers', value: 'content-managers' },
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
			{ label: 'Users', value: 'users' },
			{ label: 'Guests', value: 'guests' },
			{ label: 'Editors', value: 'editors' },
			{ label: 'Viewers', value: 'viewers' },
			{ label: 'Moderators', value: 'moderators' },
			{ label: 'Content Managers', value: 'content-managers' },
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
		const hasUserData = localStorage.getItem('nc_username') || 
						   localStorage.getItem('opencatalogi_user') ||
						   sessionStorage.getItem('nc_username')
		
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
