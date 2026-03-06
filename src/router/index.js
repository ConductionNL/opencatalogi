import Vue from 'vue'
import Router from 'vue-router'

// Views
import Dashboard from '@/views/dashboard/DashboardIndex.vue'
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
			{ path: '/', components: { default: Dashboard } },
			{ path: '/catalogi', components: { default: Catalogi } },
			{ path: '/publications/:catalogSlug', components: { default: PublicationsIndex } },
			{ path: '/publications/:catalogSlug/:id', components: { default: PublicationsIndex } },
			{ path: '/search', components: { default: Search, sidebar: SearchSideBar } },
			{ path: '/organizations', components: { default: Organizations } },
			{ path: '/themes', components: { default: Themes } },
			{ path: '/glossary', components: { default: Glossary } },
			{ path: '/pages', components: { default: Pages } },
			{ path: '/menus', components: { default: Menus } },
			{ path: '/directory', components: { default: Directory } },
			{ path: '*', redirect: '/' },
		],
	},
)
