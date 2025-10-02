import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import pinia from './pinia.js'
import App from './App.vue'
import VueMarkdownEditor from '@kangc/v-md-editor'
import '@kangc/v-md-editor/lib/style/base-editor.css'
import githubTheme from '@kangc/v-md-editor/lib/theme/github.js'
import '@kangc/v-md-editor/lib/theme/style/github.css'
import hljs from 'highlight.js'
import enUS from '@kangc/v-md-editor/lib/lang/en-US.js'

// Font Awesome setup
import { library } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { fas } from '@fortawesome/free-solid-svg-icons'
import { fab } from '@fortawesome/free-brands-svg-icons'
import { far } from '@fortawesome/free-regular-svg-icons'
// Add all Font Awesome solid icons to the library
library.add(fas, fab, far)

// Register FontAwesome component globally
Vue.component('FontAwesomeIcon', FontAwesomeIcon)

VueMarkdownEditor.use(githubTheme, {
	Hljs: hljs,
})

Vue.prototype.$vMdEditorLang = 'en-US'
Vue.prototype.$vMdEditorLangConfig = { 'en-US': enUS }

Vue.mixin({
	beforeCreate() {
		if (!this.$vMdEditorLang || !this.$vMdEditorLangConfig) {
			Vue.prototype.$vMdEditorLang = 'en-US'
			Vue.prototype.$vMdEditorLangConfig = { 'en-US': enUS }
		}
	},
})

VueMarkdownEditor.lang.use('en-US', enUS)
Vue.use(VueMarkdownEditor)

Vue.mixin({ methods: { t, n } })

Vue.use(PiniaVuePlugin)
Vue.directive('tooltip', Tooltip) // it would be nice if this was in the documentation.. NEXT CLOUD!!!

new Vue(
	{
		pinia,
		render: h => h(App),
	},
).$mount('#content')
