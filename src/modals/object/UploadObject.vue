<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
import '../../css/json-highlight.css'
</script>

<template>
	<NcDialog :name="t('opencatalogi', 'Upload Object')"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('opencatalogi', 'Object successfully uploaded') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton v-if="registers?.value?.id && !schemas?.value?.id"
				:disabled="loading"
				@click="registers.value = null">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('opencatalogi', 'Back to Register') }}
			</NcButton>
			<NcButton v-if="registers.value?.id && schemas.value?.id"
				:disabled="loading"
				@click="schemas.value = null">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('opencatalogi', 'Back to Schema') }}
			</NcButton>
			<NcButton
				@click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="!registers.value?.id || !schemas.value?.id || loading || !validateJson(object)"
				type="primary"
				@click="uploadObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Upload v-if="!loading" :size="20" />
				</template>
				{{ t('opencatalogi', 'Upload') }}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<div v-if="registers?.value?.id && success === null">
				<b>{{ t('opencatalogi', 'Register:') }}</b> {{ registers.value.label }}
				<NcButton @click="registers.value = null">
					{{ t('opencatalogi', 'Edit Register') }}
				</NcButton>
			</div>
			<div v-if="schemas.value?.id && success === null">
				<b>{{ t('opencatalogi', 'Schema:') }}</b> {{ schemas.value.label }}
				<NcButton @click="schemas.value = null">
					{{ t('opencatalogi', 'Edit Schema') }}
				</NcButton>
			</div>

			<!-- STAGE 1 -->
			<div v-if="!registers?.value?.id">
				<NcSelect v-bind="registers"
					v-model="registers.value"
					:input-label="t('opencatalogi', 'Register')"
					:loading="registersLoading"
					:disabled="loading" />
			</div>

			<!-- STAGE 2 -->
			<div v-if="registers?.value?.id && !schemas?.value?.id">
				<NcSelect v-bind="schemas"
					v-model="schemas.value"
					:input-label="t('opencatalogi', 'Schemas')"
					:loading="schemasLoading"
					:disabled="loading" />
			</div>

			<!-- STAGE 3 -->
			<div v-if="registers.value?.id && schemas.value?.id">
				<NcSelect v-bind="mappings"
					v-model="mappings.value"
					:input-label="t('opencatalogi', 'Mappings')"
					:loading="mappingsLoading"
					:disabled="loading || !mappings.options?.length" />

				<div :class="`codeMirrorContainer ${getTheme()}`">
					<p>{{ t('opencatalogi', 'Object') }}</p>
					<CodeMirror v-model="object"
						:basic="true"
						:dark="getTheme() === 'dark'"
						:lang="json()"
						:linter="jsonParseLinter()"
						:placeholder="t('opencatalogi', 'Enter your object here...')" />

					<NcButton class="prettifyButton" @click="prettifyJson">
						<template #icon>
							<AutoFix :size="20" />
						</template>
						{{ t('opencatalogi', 'Prettify') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { getTheme } from '../../services/getTheme.js'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'UploadObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		// Icons
		Cancel,
		Upload,
	},
	data() {
		return {
			object: '{}',
			schemasLoading: false,
			schemas: {},
			registersLoading: false,
			registers: {},
			mappingsLoading: false,
			mappings: {},
			success: null,
			loading: false,
			error: false,
			hasUpdated: false,
		}
	},
	/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
	mounted() {
		this.initializeMappings()
		this.initializeSchemas()
		this.initializeRegisters()
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
		initializeMappings() {
			this.mappingsLoading = true

			objectStore.getMappings()
				.then(({ data }) => {
					this.mappings = {
						multiple: false,
						closeOnSelect: true,
						options: data.map((mapping) => ({
							id: mapping.id,
							label: mapping.name,
						})),
						value: null,
					}
				})
				.finally(() => {
					this.mappingsLoading = false
				})
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
		initializeSchemas() {
			this.schemasLoading = true

			catalogStore.refreshSchemaList()
				.then(() => {
					this.schemas = {
						multiple: false,
						closeOnSelect: true,
						options: catalogStore.schemaList.map((schema) => ({
							id: schema.id,
							label: schema.title,
						})),
						value: null,
					}
				})
				.finally(() => {
					this.schemasLoading = false
				})
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
		initializeRegisters() {
			this.registersLoading = true

			catalogStore.refreshCatalogiList()
				.then(() => {
					this.registers = {
						multiple: false,
						closeOnSelect: true,
						options: catalogStore.catalogiList.map((catalogi) => ({
							id: catalogi.id,
							label: catalogi.title,
						})),
						value: null,
					}
				})
				.finally(() => {
					this.registersLoading = false
				})
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
		closeModal() {
			navigationStore.setModal(false)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.object = {
				json: '{}',
				url: '',
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
		async uploadObject() {
			this.loading = true

			const newObject = {
				object: JSON.parse(this.object) || '',
				register: this.registers.value.id || '',
				schema: this.schemas.value.id || '',
				mapping: this.mappings?.value?.id || null,
				schemas: '',
			}

			objectStore.saveObject(newObject)
				.then(({ response }) => {
					this.success = response.ok
					this.error = false
					response.ok && setTimeout(this.closeModal, 2000)
				}).catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while uploading the object'
				}).finally(() => {
					this.loading = false
				})
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
		prettifyJson() {
			this.object = JSON.stringify(JSON.parse(this.object), null, 2)
		},
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-3 */
		validateJson(json) {
			try {
				JSON.parse(json)
				return true
			} catch (error) {
				return false
			}
		},
	},
}
</script>

<style scoped>
/* CodeMirror JSON syntax-highlight colors — see src/css/json-highlight.css (imported in <script setup>). */

.prettifyButton {
	margin-block-start: 10px;
}
</style>
