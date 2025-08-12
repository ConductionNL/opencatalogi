<template>
	<div class="markdown-editor-wrapper">
		<v-md-editor
			v-model="content"
			:height="height"
			:disabled="disabled" />
	</div>
</template>

<script>
export default {
	name: 'MarkdownEditor',
	props: {
		value: {
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
	data() {
		return {
			content: this.value || '',
		}
	},
	watch: {
		value: {
			handler(newVal) {
				if (newVal !== this.content) {
					this.content = newVal || ''
				}
			},
			immediate: true,
		},
		content(newVal) {
			this.$emit('input', newVal)
		},
	},
	mounted() {
		this.$nextTick(() => {
			if (this.value && this.value !== this.content) {
				this.content = this.value
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
