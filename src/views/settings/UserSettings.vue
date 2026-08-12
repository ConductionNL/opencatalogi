<!--
	UNREACHABLE COMPONENT — no visual baseline is possible.

	Nothing imports this file: `src/registry.js` is the only place page
	components are handed to CnAppRoot, and it does not list it. The settings
	dialog a user actually opens from the `SettingsMenu` nav entry is rendered by
	the manifest app shell, not by this file.

	Bundle check, with a positive control: `"UserSettings"` occurs **0** times in
	`js/opencatalogi-main.js`, while every wired view occurs at least once
	(`"CatalogDetailPage"` 1, `"Dashboard"` 7, `"FederationSearch"` 2) — webpack
	tree-shakes it out entirely.

	⚠️ This component was NOT in gate-26's finding list, and not because it was
	covered. The only occurrence of the string `UserSettings` anywhere under
	`tests/e2e/` is one docblock line in `gate19.spec.ts` reading
	"WHEN UserSettings.vue renders"; the SET-017 test beneath it opens a settings
	dialog that this file does not render. Measured: neutering that one word took
	gate-26 from PASS to `FAIL — 2` naming this file and PublicationDetail.vue.
	See ConductionNL/opencatalogi#849 and ConductionNL/.github#358.

	@visual exclude Unreachable: imported by nothing, absent from the shipped bundle (0 occurrences, against 1-7 for every wired view); the settings dialog is rendered by the manifest app shell. Tracked in ConductionNL/opencatalogi#849.
-->
<template>
	<NcAppSettingsDialog
		:open="open"
		:show-navigation="false"
		:name="t('opencatalogi', 'OpenCatalogi settings')"
		@update:open="$emit('update:open', $event)">
		<NcAppSettingsSection id="general" :name="t('opencatalogi', 'General')">
			<template #icon>
				<CogIcon :size="20" />
			</template>
			<p>{{ t('opencatalogi', 'User preferences will appear here.') }}</p>
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>
<script>
import { NcAppSettingsDialog, NcAppSettingsSection } from '@nextcloud/vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
/**
 * UserSettings — placeholder user-preferences dialog.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
export default {
	name: 'UserSettings',
	components: { NcAppSettingsDialog, NcAppSettingsSection, CogIcon },
	props: { open: { type: Boolean, default: false } },
}
</script>
