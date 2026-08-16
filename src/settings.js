// Font Awesome setup for settings page
import { library } from '@fortawesome/fontawesome-svg-core'
import { fab } from '@fortawesome/free-brands-svg-icons'
import { far } from '@fortawesome/free-regular-svg-icons'
import { fas } from '@fortawesome/free-solid-svg-icons'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import VueMarkdownEditor from '@kangc/v-md-editor'
import enUS from '@kangc/v-md-editor/lib/lang/en-US.js'
import githubTheme from '@kangc/v-md-editor/lib/theme/github.js'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import hljs from 'highlight.js'
/**
 * Admin settings bundle entry-point — mounts Settings.vue on #settings and
 * registers the markdown editor + FontAwesome library.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { createApp } from 'vue'
import AdminSettings from './views/settings/Settings.vue'

import '@kangc/v-md-editor/lib/style/base-editor.css'
import '@kangc/v-md-editor/lib/theme/style/github.css'

// Add all Font Awesome solid icons to the library
library.add(fas, fab, far)

VueMarkdownEditor.use(githubTheme, {
	Hljs: hljs,
})
VueMarkdownEditor.lang.use('en-US', enUS)

const app = createApp(AdminSettings)

// Vue 3 has no `Vue.prototype`; per-app globals live on
// `app.config.globalProperties`. The old `beforeCreate` mixin existed only to
// re-seed the prototype if a component was created before the assignment ran —
// impossible now that both are set on the app handle before `mount()`.
app.config.globalProperties.$vMdEditorLang = 'en-US'
app.config.globalProperties.$vMdEditorLangConfig = { 'en-US': enUS }

app.mixin({ methods: { t, n } })
app.use(VueMarkdownEditor)
app.component('FontAwesomeIcon', FontAwesomeIcon)

// ⚠️ Mount host renamed from `#settings` to `#opencatalogi-settings` (see
// `templates/settings/admin.php`). Vue 2's `$mount()` REPLACED the matched
// element; Vue 3's `mount()` renders INSIDE it, so a generic id shared with any
// other settings section on the page would nest this app in the wrong place. A
// uniquely named host removes the question entirely.
app.mount('#opencatalogi-settings')
