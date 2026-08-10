<script setup>
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Generic tab strip — a drop-in replacement for `bootstrap-vue`'s `<BTabs>`.
 *
 * WHY THIS EXISTS
 * ---------------
 * `bootstrap-vue@2` is Vue-2-only and has NO Vue 3 successor (bootstrap-vue-next
 * is a separate, differently-shaped project). Neither `@nextcloud/vue@9` nor
 * `@conduction/nextcloud-vue` ships a generic tab strip: the only tab component
 * in either library is `NcAppSidebarTab`, which is meaningless outside an
 * `NcAppSidebar` parent. Six components in this app used `<BTabs>/<BTab>` inside
 * MODALS and a detail view, none of which have a sidebar.
 *
 * ⚠️ A generic `CnTabs` / `CnTab` pair belongs in `@conduction/nextcloud-vue`,
 * not here. This local pair exists so the Vue 3 migration is not blocked on a
 * library release; it deliberately mirrors the `<BTabs>` API exactly
 * (`v-model` index, `content-class`, `justified`, `<BTab :title>` and
 * `<BTab><template #title>`) so lifting it into the library later is a rename.
 *
 * BEHAVIOURAL FIDELITY
 * --------------------
 * `<BTabs>` keeps inactive panels MOUNTED and merely hides them (its `lazy`
 * mode is opt-in). This does the same, via `v-show` inside `<AppTab>`. That is
 * not cosmetic: several tabs here hold form state that must survive a tab
 * switch, and it is also what makes tab ORDER reliable — panels are always in
 * the DOM, so the strip can order its buttons by real document position rather
 * than by registration timing, which `v-if`-ed tabs would otherwise scramble.
 */
import { computed, provide, ref, watch } from 'vue'
import { TABS_CONTEXT } from './tabsContext.js'

const props = defineProps({
	/** Zero-based index of the active tab. */
	modelValue: {
		type: Number,
		default: 0,
	},
	/** Class applied to the panel container (matches `<BTabs content-class>`). */
	contentClass: {
		type: String,
		default: '',
	},
	/** Stretch the buttons to fill the strip (matches `<BTabs justified>`). */
	justified: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['update:modelValue'])

/**
 * Registered child tabs, in registration order. Each entry is
 * `{ uid, title, titleSlot, el }` where `el` is a ref to the panel element.
 *
 * @type {import('vue').Ref<Array<object>>}
 */
const tabs = ref([])

/** Internal selection, used when the parent does not bind `v-model`. */
const internalIndex = ref(props.modelValue)

watch(() => props.modelValue, (next) => {
	if (typeof next === 'number' && next !== internalIndex.value) {
		internalIndex.value = next
	}
})

/**
 * Child panels ordered by real DOM position.
 *
 * Registration order alone is wrong as soon as a tab carries `v-if`: a tab that
 * appears later would append to the end of the array regardless of where its
 * markup sits. `compareDocumentPosition` asks the document instead, which is
 * always right because every panel stays mounted.
 */
const orderedTabs = computed(() => {
	return [...tabs.value].sort((a, b) => {
		const ea = a.el?.value
		const eb = b.el?.value
		if (!ea || !eb) {
			return 0
		}
		// eslint-disable-next-line no-bitwise
		return (ea.compareDocumentPosition(eb) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1
	})
})

const activeIndex = computed(() => {
	const count = orderedTabs.value.length
	if (count === 0) {
		return 0
	}
	// Clamp: a `v-if`-ed tab disappearing must not leave the strip pointing
	// past the end, which would render no panel at all and look like a blank
	// modal body.
	return Math.min(Math.max(internalIndex.value, 0), count - 1)
})

/**
 * Select a tab by its position in the ordered list.
 *
 * @param {number} index Zero-based index.
 * @return {void}
 */
function select(index) {
	internalIndex.value = index
	emit('update:modelValue', index)
}

provide(TABS_CONTEXT, {
	/**
	 * Register a child panel.
	 *
	 * @param {object} tab `{ uid, title, titleSlot, el, active }`.
	 * @return {void}
	 * @spec openspec/specs/generic-object-modals/spec.md#requirement-provide-shared-object-presentation-components-gom-005
	 */
	register(tab) {
		tabs.value = [...tabs.value, tab]
		// `<BTab active>` marks the initially selected tab. Honour it only
		// while the strip is still on its initial selection, so it can never
		// fight an explicit `v-model` the parent has already moved.
		if (tab.active && internalIndex.value === props.modelValue) {
			const index = orderedTabs.value.findIndex((t) => t.uid === tab.uid)
			if (index > 0) {
				select(index)
			}
		}
	},
	/**
	 * Unregister a child panel.
	 *
	 * @param {number|string} uid The tab's unique id.
	 * @return {void}
	 * @spec openspec/specs/generic-object-modals/spec.md#requirement-provide-shared-object-presentation-components-gom-005
	 */
	unregister(uid) {
		tabs.value = tabs.value.filter((t) => t.uid !== uid)
	},
	/**
	 * Whether the given tab is the active one.
	 *
	 * @param {number|string} uid The tab's unique id.
	 * @return {boolean} True when active.
	 */
	isActive(uid) {
		return orderedTabs.value[activeIndex.value]?.uid === uid
	},
})
</script>

<template>
	<div class="app-tabs">
		<div
			class="app-tabs__nav"
			:class="{ 'app-tabs__nav--justified': justified }"
			role="tablist">
			<button
				v-for="(tab, index) in orderedTabs"
				:key="tab.uid"
				type="button"
				role="tab"
				class="app-tabs__button"
				:class="{ 'app-tabs__button--active': index === activeIndex }"
				:aria-selected="index === activeIndex"
				@click="select(index)">
				<!-- `<BTab>` accepts EITHER a `title` prop or a `#title` slot.
				     A slot function is a valid functional component in Vue 3,
				     so it can be rendered straight through `<component :is>`. -->
				<component :is="tab.titleSlot" v-if="tab.titleSlot" />
				<template v-else>
					{{ tab.title }}
				</template>
			</button>
		</div>

		<div class="app-tabs__content" :class="contentClass">
			<slot />
		</div>
	</div>
</template>

<style scoped>
.app-tabs__nav {
	display: flex;
	gap: 2px;
	border-bottom: 1px solid var(--color-border);
}

.app-tabs__nav--justified .app-tabs__button {
	flex: 1 1 0;
}

.app-tabs__button {
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	border-radius: 0;
	padding: 10px 14px;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	cursor: pointer;
	white-space: nowrap;
}

.app-tabs__button:hover,
.app-tabs__button:focus-visible {
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.app-tabs__button--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: bold;
}
</style>
