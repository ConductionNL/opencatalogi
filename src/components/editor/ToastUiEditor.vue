<script setup>
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Minimal Vue 3 wrapper around the framework-agnostic `@toast-ui/editor` core.
 *
 * WHY THIS EXISTS
 * ---------------
 * `@toast-ui/vue-editor` declares `vue: ^2.5.0` as its peer dependency and has
 * no Vue 3 release, so it was dropped from package.json. The core
 * `@toast-ui/editor` package is plain TypeScript with no framework binding and
 * is still installed, so the whole Vue-2 wrapper amounts to "construct in
 * mounted, destroy in unmount, bridge one event" — which is all this does.
 *
 * ⚠️ This belongs in `@conduction/nextcloud-vue` as a `CnMarkdownEditor`
 * alongside the other shared editors rather than in this app: three Conduction
 * apps embed a rich-text editor and each would otherwise carry its own copy.
 * It is kept local so the Vue 3 migration is not blocked on a library release.
 *
 * HTML, NOT MARKDOWN
 * ------------------
 * `modelValue` is **HTML**, because that is what the only consumer stores and
 * reads back (`PageContentForm` persists `data.content` from `getHTML()`).
 *
 * Toast UI's `initialValue` constructor option is interpreted as MARKDOWN. The
 * Vue-2 usage passed the stored HTML straight into `:initial-value`, so
 * reopening a saved RichText block showed escaped HTML source instead of the
 * rendered document. Seeding through `setHTML()` after construction is what
 * makes the round-trip actually close; see the report accompanying the
 * migration.
 */
import Editor from '@toast-ui/editor'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
	/** Editor contents as an HTML string. */
	modelValue: {
		type: String,
		default: '',
	},
	/**
	 * Raw Toast UI constructor options (toolbarItems, language, minHeight, …).
	 * Merged last-but-one so callers keep full control, while `el` stays ours.
	 */
	options: {
		type: Object,
		default: () => ({}),
	},
	/** 'wysiwyg' or 'markdown'. */
	initialEditType: {
		type: String,
		default: 'wysiwyg',
	},
	/** 'tab' or 'vertical'. */
	previewStyle: {
		type: String,
		default: 'tab',
	},
	/** CSS height for the editor shell. */
	height: {
		type: String,
		default: '300px',
	},
})

const emit = defineEmits(['update:modelValue', 'load'])

/** Host element the editor mounts into. */
const el = ref(null)

/**
 * The live editor instance.
 *
 * Deliberately a plain `let` and not a `ref`: Toast UI's instance is a large
 * mutable object graph, and wrapping it in Vue's reactivity proxy makes the
 * editor observe its own internal writes and can corrupt selection state.
 *
 * @type {import('@toast-ui/editor').default|null}
 */
let editor = null

/**
 * Last HTML this component emitted. Used to distinguish "the parent changed the
 * value" from "the parent is echoing back what we just sent it", so the watcher
 * below never re-seeds the editor mid-keystroke and throws away the caret.
 *
 * @type {string}
 */
let lastEmitted = ''

onMounted(() => {
	editor = new Editor({
		el: el.value,
		height: props.height,
		previewStyle: props.previewStyle,
		initialEditType: props.initialEditType,
		...props.options,
	})

	// Seed as HTML rather than via the constructor's markdown `initialValue`.
	if (props.modelValue) {
		editor.setHTML(props.modelValue, false)
	}

	editor.on('change', () => {
		lastEmitted = editor.getHTML()
		emit('update:modelValue', lastEmitted)
	})

	// Preserves the Vue-2 `@load` contract: the parent captured the instance
	// this way and read `getHTML()` from it at save time.
	emit('load', editor)
})

onBeforeUnmount(() => {
	if (editor) {
		editor.off('change')
		editor.destroy()
		editor = null
	}
})

watch(() => props.modelValue, (next) => {
	if (!editor) {
		return
	}
	// Ignore the echo of our own emit, and any value already on screen —
	// `setHTML` resets the selection, so calling it while typing is visible.
	if (next === lastEmitted || next === editor.getHTML()) {
		return
	}
	editor.setHTML(next || '', false)
})

defineExpose({
	/**
	 * Current contents as HTML.
	 *
	 * @return {string} The HTML string.
	 */
	getHTML: () => (editor ? editor.getHTML() : ''),
	/**
	 * Current contents as Markdown.
	 *
	 * @return {string} The Markdown string.
	 */
	getMarkdown: () => (editor ? editor.getMarkdown() : ''),
})
</script>

<template>
	<div ref="el" class="toastui-editor-host" />
</template>
