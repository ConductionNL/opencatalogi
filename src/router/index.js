import Vue from 'vue'
import Router from 'vue-router'

// Views
import Dashboard from '@/views/dashboard/Dashboard.vue'
import Catalogi from '@/views/catalogi/CatalogiIndex.vue'
import PublicationsIndex from '@/views/publications/PublicationIndex.vue'
import Search from '@/views/search/SearchIndex.vue'
import Organizations from '@/views/organizations/OrganizationIndex.vue'
import Themes from '@/views/themes/ThemeIndex.vue'
import Pages from '@/views/pages/PageIndex.vue'
import Menus from '@/views/menus/MenuIndex.vue'
import Directory from '@/views/directory/DirectoryIndex.vue'
import Glossary from '@/views/glossary/GlossaryIndex.vue'

// Sidebars (named views)
import SearchSideBar from '@/sidebars/search/SearchSideBar.vue'

Vue.use(Router)

export default new Router(
	{
		mode: 'history',
		base: '/index.php/apps/opencatalogi/',
		routes: [
			{ path: '/', name: 'Dashboard', components: { default: Dashboard } },
			{ path: '/catalogi', name: 'Catalogs', components: { default: Catalogi } },
			{ path: '/publications/:catalogSlug', name: 'Publications', components: { default: PublicationsIndex } },
			{ path: '/publications/:catalogSlug/:id', name: 'PublicationDetail', components: { default: PublicationsIndex } },
			{ path: '/search', name: 'Search', components: { default: Search, sidebar: SearchSideBar } },
			{ path: '/organizations', name: 'Organizations', components: { default: Organizations } },
			{ path: '/themes', name: 'Themes', components: { default: Themes } },
			{ path: '/glossary', name: 'Glossary', components: { default: Glossary } },
			{ path: '/pages', name: 'Pages', components: { default: Pages } },
			{ path: '/menus', name: 'Menus', components: { default: Menus } },
			{ path: '/directory', name: 'Directory', components: { default: Directory } },
			{ path: '*', redirect: '/' },
		],
	},
)
