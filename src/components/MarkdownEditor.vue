<template>
	<div class="markdown-editor-wrapper">
		<v-md-editor v-model="content" :height="height" :disabled="disabled" />
	</div>
</template>

<script>
/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'MarkdownEditor',
	props: {
		modelValue: {
			type: String,
			default: '',
		},
		height: {
			type: String,
			default: '400px',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	// Vue 3 v-model: the `value`/`input` pair of Vue 2 became
	// `modelValue`/`update:modelValue`. Keeping the old names would leave every
	// `v-model` on this component silently dead.
	emits: ['update:modelValue'],
	data() {
		return {
			content: this.modelValue || '',
		}
	},
	watch: {
		modelValue: {
			/** @spec openspec/changes/retrofit-2026-05-26-object-table-listing/tasks.md#task-4 */
			handler(newVal) {
				if (newVal !== this.content) {
					this.content = newVal || ''
				}
			},
			immediate: true,
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-table-listing/tasks.md#task-4 */
		content(newVal) {
			this.$emit('update:modelValue', newVal)
		},
	},
	/** @spec openspec/changes/retrofit-2026-05-26-object-table-listing/tasks.md#task-4 */
	mounted() {
		this.$nextTick(() => {
			if (this.modelValue && this.modelValue !== this.content) {
				this.content = this.modelValue
			}
		})
	},
}
</script>

<style scoped>
.markdown-editor-wrapper {
	text-align: left;
}
</style>
