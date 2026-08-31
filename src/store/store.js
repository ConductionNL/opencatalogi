/* eslint-disable n/no-missing-import */
/* eslint-disable import/extensions */
// `import/no-unresolved` is NOT disabled here: the flat config does not
// register it, so the disable comment was itself an error ("Definition for
// rule 'import/no-unresolved' was not found").
// fk these rules above here

// The store script handles app wide variables (or state), for the use of these variables and there governing concepts read the design.md
import pinia from '../pinia.js'
import { useCatalogStore } from './modules/catalog' // Import the catalog store
import { useNavigationStore } from './modules/navigation'
import { useObjectStore } from './modules/object' // Import the object store
import { useSearchStore } from './modules/search'

const navigationStore = useNavigationStore(pinia)
const searchStore = useSearchStore(pinia)
const objectStore = useObjectStore(pinia) // Initialize the object store
const catalogStore = useCatalogStore(pinia) // Initialize the catalog store

export {
	catalogStore, // Export the catalog store
	// generic
	navigationStore,
	objectStore, // Export the object store
	searchStore,
}
