<script setup>
import { navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'viewLog'"
		name="Bekijk log regel"
		:can-close="false">
		<table width="100%">
			<tr>
				<td><b>Tijdstip</b></td>
				<td>Ruben van der Linde</td>
			</tr>
			<tr>
				<td><b>Gebruiker</b></td>
				<td>Ruben van der Linde</td>
			</tr>
			<tr>
				<td><b>Actie</b></td>
				<td>Created</td>
			</tr>
			<tr>
				<td><b>Wijzigingen</b></td>
				<td>
					<div class="changes-container">
						<span class="changes-text">
							{
							"title": {
							"old":Null
							"new":"KOPIE: Voorlopige energielabels met BAG-kenmerken"
							}
							}</span>
					</div>
				</td>
			</tr>
		</table>
		<template #actions>
			<NcButton :disabled="loading" @click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Sluiten
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'ViewLogDialog',
	components: {
		NcDialog,
		NcButton,
		// Icons
		Cancel,
	},
	data() {
		return {

			loading: false,
		}
	},
}
</script>

<style>
.modal__content {
    margin: var(--OC-margin-50);
    text-align: center;
}

.zaakDetailsContainer {
    margin-block-start: var(--OC-margin-20);
    margin-inline-start: var(--OC-margin-20);
    margin-inline-end: var(--OC-margin-20);
}

.success {
    color: green;
}
.changes-container {
  display: flex;
  align-items: center;
}

.changes-text {
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  max-width: 300px;
}
</style>
