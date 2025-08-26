import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AdminSettings from './views/settings/Settings.vue'
import VueMarkdownEditor from '@kangc/v-md-editor'
import '@kangc/v-md-editor/lib/style/base-editor.css'
import githubTheme from '@kangc/v-md-editor/lib/theme/github.js'
import '@kangc/v-md-editor/lib/theme/style/github.css'
import hljs from 'highlight.js'
import enUS from '@kangc/v-md-editor/lib/lang/en-US.js'

// Font Awesome setup for settings page
import { library } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { fas } from '@fortawesome/free-solid-svg-icons'

// Add all Font Awesome solid icons to the library
library.add(fas)

// Register FontAwesome component globally for settings
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

new Vue(
	{
		render: h => h(AdminSettings),
	},
).$mount('#settings')
