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
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'

export default {
	name: 'PropertiesPanel',
	components: {
		NcButton,
		CnPropertiesTab,
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
		canDropProperty: { type: Function, required: true },
		getDropPropertyTooltip: { type: Function, required: true },
	},
	emits: [
		'update:selected-property',
		'update:property-value',
		'update:show-constant-properties',
		'drop-property',
	],
}
</script>
