<template>
	<div class="properties-section">
		<CnPropertiesTab
			:schema="resolvedSchema"
			:item="currentObject || {}"
			:formData="formData"
			:selectedProperty="selectedProperty"
			:propertyOverrides="propertyOverrides"
			:isNew="isNew"
			@update:selectedProperty="$emit('update:selected-property', $event)"
			@update:propertyValue="$emit('update:property-value', $event)">
			<template #row-actions="{ propertyKey, resolvedValue }">
				<NcButton
					v-if="canDropProperty(propertyKey, resolvedValue)"
					:title="getDropPropertyTooltip(propertyKey)"
					variant="tertiary-no-background"
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
import { CnPropertiesTab } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
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
		isNew: { type: Boolean, default: false },
	},

	emits: ['update:selected-property', 'update:property-value', 'drop-property'],
}
</script>
