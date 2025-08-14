import Vue from 'vue'
import AdminSettings from './views/settings/Settings.vue'
import VueMarkdownEditor from '@kangc/v-md-editor'
import '@kangc/v-md-editor/lib/style/base-editor.css'
import githubTheme from '@kangc/v-md-editor/lib/theme/github.js'
import '@kangc/v-md-editor/lib/theme/style/github.css'
import hljs from 'highlight.js'
import enUS from '@kangc/v-md-editor/lib/lang/en-US.js'

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
