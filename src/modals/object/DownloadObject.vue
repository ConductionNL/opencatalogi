<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import '../../css/json-highlight.css'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'downloadObject'"
		:name="t('opencatalogi', 'Download {name}', { name: objectStore.objectItem?.['@self']?.name || objectStore.objectItem?.name || objectStore.objectItem?.['@self']?.title || objectStore.objectItem?.id || t('opencatalogi', 'Publication') })"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('opencatalogi', 'Object successfully downloaded') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<div class="json-editor">
				<label>{{ t('opencatalogi', 'Object (JSON)') }}</label>
				<div :class="`codeMirrorContainer ${getTheme()}`">
					<CodeMirror v-model="objectItem.object"
						:basic="true"
						placeholder="{ &quot;key&quot;: &quot;value&quot; }"
						:dark="getTheme() === 'dark'"
						:linter="jsonParseLinter()"
						:lang="json()"
						:tab-size="2" />
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { getTheme } from '../../services/getTheme.js'
import {
	NcDialog,
	NcButton,
	NcNoteCard,
} from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'

import Cancel from 'vue-material-design-icons/Cancel.vue'

/**
 * @spec openspec/changes/retrofit-2026-05-25-generic-object-modals/tasks.md#task-1
 */
export default {
	name: 'DownloadObject',
	components: {
		// components
		NcDialog,
		NcButton,
		NcNoteCard,
		CodeMirror,
		// icons
		Cancel,
	},
	data() {
		return {
			// store
			objectStore,
			navigationStore,
			// state
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-5 */
	mounted() {
		if (objectStore.objectItem?.id) {
			this.downloadObject()
		}
	},
	methods: {
		json,
		jsonParseLinter,
		getTheme,
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-5 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-5 */
		async downloadObject() {
			this.loading = true

			try {
				const response = await objectStore.downloadObject(objectStore.objectItem)
				this.success = response.ok
				this.error = false
				if (response.ok) {
					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
				}
			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while downloading the object'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.json-editor {
	position: relative;
	margin-bottom: 2.5rem;
}

.json-editor label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: bold;
}
</style>
