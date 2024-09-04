import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import pinia from './pinia.js'
import App from './App.vue'
Vue.mixin({ methods: { t, n } })

Vue.use(PiniaVuePlugin)
Vue.directive('tooltip', Tooltip) // it would be nice if this was in the documentation.. NEXT CLOUD!!!

new Vue(
	{
		pinia,
		render: h => h(App),
	},
).$mount('#content')
