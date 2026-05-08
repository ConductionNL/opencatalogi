import Vue from 'vue'
import Router from 'vue-router'

// Views
import Dashboard from '@/views/dashboard/Dashboard.vue'
import Catalogi from '@/views/catalogi/CatalogiIndex.vue'
import CatalogDetailPage from '@/views/catalogi/CatalogDetailPage.vue'
import PublicationsIndex from '@/views/publications/PublicationIndex.vue'
import PublicationDetailPage from '@/views/publications/PublicationDetailPage.vue'
import Search from '@/views/search/SearchIndex.vue'
import Organizations from '@/views/organizations/OrganizationIndex.vue'
import Themes from '@/views/themes/ThemeIndex.vue'
import ThemeDetailPage from '@/views/themes/ThemeDetailPage.vue'
import Pages from '@/views/pages/PageIndex.vue'
import PageDetailPage from '@/views/pages/PageDetailPage.vue'
import Menus from '@/views/menus/MenuIndex.vue'
import MenuDetailPage from '@/views/menus/MenuDetailPage.vue'
import Directory from '@/views/directory/DirectoryIndex.vue'
import Glossary from '@/views/glossary/GlossaryIndex.vue'
import GlossaryDetailPage from '@/views/glossary/GlossaryDetailPage.vue'

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
			{ path: '/catalogi/:id', name: 'CatalogDetail', components: { default: CatalogDetailPage } },
			{ path: '/publications/:catalogSlug', name: 'Publications', components: { default: PublicationsIndex } },
			{ path: '/publications/:catalogSlug/:id', name: 'PublicationDetail', components: { default: PublicationDetailPage } },
			{ path: '/search', name: 'Search', components: { default: Search, sidebar: SearchSideBar } },
			{ path: '/organizations', name: 'Organizations', components: { default: Organizations } },
			{ path: '/themes', name: 'Themes', components: { default: Themes } },
			{ path: '/themes/:id', name: 'ThemeDetail', components: { default: ThemeDetailPage } },
			{ path: '/glossary', name: 'Glossary', components: { default: Glossary } },
			{ path: '/glossary/:id', name: 'GlossaryDetail', components: { default: GlossaryDetailPage } },
			{ path: '/pages', name: 'Pages', components: { default: Pages } },
			{ path: '/pages/:id', name: 'PageDetail', components: { default: PageDetailPage } },
			{ path: '/menus', name: 'Menus', components: { default: Menus } },
			{ path: '/menus/:id', name: 'MenuDetail', components: { default: MenuDetailPage } },
			{ path: '/directory', name: 'Directory', components: { default: Directory } },
			{ path: '*', redirect: '/' },
		],
	},
)
