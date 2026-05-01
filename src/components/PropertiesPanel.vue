<template>
	<div class="properties-section">
		<CnPropertiesTab
			:schema="resolvedSchema"
			:item="currentObject || {}"
			:form-data="formData"
			:selected-property="selectedProperty"
			:property-overrides="propertyOverrides"
			@update:selected-property="$emit('update:selected-property', $event)"
			@update:property-value="$emit('update:property-value', $event)">
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
import { CnPropertiesTab } from '@conduction/nextcloud-vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'PropertiesPanel',
	components: {
		NcButton,
		CnPropertiesTab,
		Close,
	},
	props: {
		resolvedSchema: { type: Object, default: null },
		currentObject: { type: Object, default: null },
		formData: { type: Object, required: true },
		selectedProperty: { type: String, default: null },
		propertyOverrides: { type: Object, default: () => ({}) },
		canDropProperty: { type: Function, required: true },
		getDropPropertyTooltip: { type: Function, required: true },
	},
	emits: [
		'update:selected-property',
		'update:property-value',
		'drop-property',
	],
}
</script>
