<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="dcat-default-licence" data-testid="dcat-default-licence">
		<h3 class="dcat-default-licence__heading">
			{{ t('opencatalogi', 'Default licence') }}
		</h3>
		<p class="dcat-default-licence__hint">
			{{ t('opencatalogi', 'The licence the DCAT feed gives a catalogue that names none. Chosen from the EU licences list; with none chosen the feed carries no default licence.') }}
		</p>
		<LicencePicker
			v-if="loaded"
			:modelValue="licence"
			:inputLabel="t('opencatalogi', 'Default licence')"
			:disabled="saving"
			@update:modelValue="save" />
		<NcNoteCard v-if="message" :type="messageType" data-testid="dcat-default-licence-message">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'
import LicencePicker from '../../components/LicencePicker.vue'

/** The setting this block reads and writes. */
const KEY = 'dcat_default_license'

/**
 * DcatDefaultLicence: the instance's default DCAT licence, picked from the
 * EU licences authority list (REQ-DCC-001). Saved on choice through the
 * settings endpoint, which stores the member's authority IRI and refuses a
 * value outside the list.
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
export default {
	name: 'DcatDefaultLicence',
	components: { LicencePicker, NcNoteCard },

	data() {
		return {
			licence: '',
			loaded: false,
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	/**
	 * Read the stored default licence.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 */
	async mounted() {
		try {
			const response = await axios.get(generateUrl('/apps/opencatalogi/api/settings'))
			this.licence = String(response.data?.configuration?.[KEY] ?? '')
		} catch {
			this.show(t('opencatalogi', 'The default licence could not be read.'), 'error')
		} finally {
			this.loaded = true
		}
	},

	methods: {
		t,

		/**
		 * Save the chosen licence and show what was stored.
		 *
		 * @param {string} iri The chosen licence's IRI, or empty for none.
		 * @return {Promise<void>}
		 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
		 */
		async save(iri) {
			const previous = this.licence
			this.licence = iri
			this.saving = true
			try {
				const response = await axios.put(generateUrl('/apps/opencatalogi/api/settings'), { [KEY]: iri })
				const stored = response.data?.configuration?.[KEY] ?? response.data?.[KEY]
				if (typeof stored === 'string') {
					this.licence = stored
				}
				this.show(t('opencatalogi', 'Default licence saved.'), 'success')
			} catch (e) {
				this.licence = previous
				this.show(e?.response?.data?.error || t('opencatalogi', 'The default licence could not be saved.'), 'error')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Show a message under the picker.
		 *
		 * @param {string} text The message.
		 * @param {string} type The note type.
		 * @return {void}
		 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
		 */
		show(text, type) {
			this.message = text
			this.messageType = type
		},
	},
}
</script>
