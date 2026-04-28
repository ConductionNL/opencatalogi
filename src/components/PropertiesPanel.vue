<template>
	<div class="properties-section">
		<div v-if="hasConstantOrImmutableProperties" class="properties-toolbar">
			<NcButton
				v-tooltip="showConstantProperties ? 'Hide constant & immutable properties' : 'Show constant & immutable properties'"
				type="primary"
				size="small"
				class="eye-toggle-btn"
				:aria-label="showConstantProperties ? 'Hide constant & immutable properties' : 'Show constant & immutable properties'"
				@click="$emit('update:show-constant-properties', !showConstantProperties)">
				<template #icon>
					<Eye v-if="!showConstantProperties" :size="16" />
					<EyeOff v-else :size="16" />
				</template>
			</NcButton>
		</div>
		<CnPropertiesTab
			:schema="resolvedSchema"
			:item="currentObject || {}"
			:form-data="formData"
			:selected-property="selectedProperty"
			:show-constant-properties="showConstantProperties"
			:property-overrides="propertyOverrides"
			@update:selected-property="$emit('update:selected-property', $event)"
			@update:property-value="$emit('update:property-value', $event)">
			<template #value-cell="{ propertyKey, resolvedValue, isEditing, isEditable, displayName, schemaProp, editabilityWarning, onUpdate }">
				<Editor
					v-if="isMarkdownProperty(schemaProp) && isEditing"
					:key="`editor-${propertyKey}`"
					:initial-value="String(resolvedValue || '')"
					:options="getMarkdownEditorOptions(propertyKey)"
					initial-edit-type="wysiwyg"
					height="400px"
					@load="(editor) => $emit('editor-load', { propertyKey, editor })"
					@blur="$emit('editor-blur', { propertyKey, onUpdate })" />
				<CnPropertyValueCell
					v-else
					:property-key="propertyKey"
					:schema="resolvedSchema"
					:value="resolvedValue"
					:is-editable="isEditable"
					:is-editing="isEditing"
					:display-name="displayName"
					:editability-warning="editabilityWarning"
					:widget="(propertyOverrides[propertyKey] && propertyOverrides[propertyKey].widget) || null"
					:select-options="(propertyOverrides[propertyKey] && propertyOverrides[propertyKey].selectOptions) || null"
					:select-multiple="propertyOverrides[propertyKey] ? propertyOverrides[propertyKey].selectMultiple !== false : true"
					@update:value="onUpdate" />
			</template>
			<template #row-actions="{ propertyKey, resolvedValue }">
				<NcButton
					v-if="canDropProperty(propertyKey, resolvedValue)"
					v-tooltip="getDropPropertyTooltip(propertyKey)"
					type="tertiary-no-background"
					size="small"
					class="drop-property-btn"
					:aria-label="getDropPropertyTooltip(propertyKey)"
					@click.stop="$emit('drop-property', propertyKey)">
					<template #icon>
						<Close :size="16" />
					</template>
				</NcButton>
			</template>
		</CnPropertiesTab>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnPropertiesTab, CnPropertyValueCell } from '@conduction/nextcloud-vue'
import { Editor } from '@toast-ui/vue-editor'
import Close from 'vue-material-design-icons/Close.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'

export default {
	name: 'PropertiesPanel',
	components: {
		NcButton,
		CnPropertiesTab,
		CnPropertyValueCell,
		Editor,
		Close,
		Eye,
		EyeOff,
	},
	props: {
		resolvedSchema: { type: Object, default: null },
		currentObject: { type: Object, default: null },
		formData: { type: Object, required: true },
		selectedProperty: { type: String, default: null },
		showConstantProperties: { type: Boolean, default: false },
		hasConstantOrImmutableProperties: { type: Boolean, default: false },
		propertyOverrides: { type: Object, default: () => ({}) },
		isMarkdownProperty: { type: Function, required: true },
		getMarkdownEditorOptions: { type: Function, required: true },
		canDropProperty: { type: Function, required: true },
		getDropPropertyTooltip: { type: Function, required: true },
	},
	emits: [
		'update:selected-property',
		'update:property-value',
		'update:show-constant-properties',
		'drop-property',
		'editor-load',
		'editor-blur',
	],
}
</script>
