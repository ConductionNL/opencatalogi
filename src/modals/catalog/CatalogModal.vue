<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl> -->
<script setup>
import { useIsAdmin } from '../../composables/useIsAdmin.js'
import { navigationStore, objectStore } from '../../store/store.js'

const { isAdmin } = useIsAdmin()
</script>

<template>
	<!-- catalogs: create/edit runs in CnFormDialog; the fields stay in its #form slot. -->
	<CnFormDialog
		v-if="navigationStore.modal === 'catalog'"
		ref="formDialog"
		:item="isEdit ? objectStore.getActiveObject('catalog') : null"
		:schema="dialogSchema"
		:dialogTitle="
			isEdit
				? t('opencatalogi', 'Catalog edit')
				: t('opencatalogi', 'Add Catalog')
		"
		:successText="
			isEdit
				? t('opencatalogi', 'Catalog successfully edited')
				: t('opencatalogi', 'Catalog successfully added')
		"
		:confirmLabel="isEdit ? t('opencatalogi', 'Save') : t('opencatalogi', 'Add')"
		:cancelLabel="isEdit ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel')"
		:closeLabel="t('opencatalogi', 'Close')"
		:confirmDisabled="!inputValidation.success"
		:feedback="false"
		:cancelIcon="dismissIcon"
		@confirm="saveCatalog"
		@close="closeModal">
		<template #form>
			<div class="form-group">
				<TranslationLanguageSwitch
					v-if="isEdit && editLanguages.length > 1"
					:languages="editLanguages"
					:modelValue="editLanguage"
					:outdated="editLanguageOutdated"
					:disabled="objectStore.isLoading('catalog')"
					@update:modelValue="setEditLanguage" />
				<NcNoteCard v-if="translationError" type="error">
					<p>{{ translationError }}</p>
				</NcNoteCard>
				<NcTextField
					v-model="textFields.title"
					:disabled="objectStore.isLoading('catalog')"
					:label="t('opencatalogi', 'Title*')"
					maxlength="255"
					:error="!!inputValidation.fieldErrors?.['title']"
					:helperText="inputValidation.fieldErrors?.['title']?.[0]" />
				<NcTextField
					v-model="textFields.summary"
					:disabled="objectStore.isLoading('catalog')"
					:label="t('opencatalogi', 'Summary')"
					maxlength="255"
					:error="!!inputValidation.fieldErrors?.['summary']"
					:helperText="inputValidation.fieldErrors?.['summary']?.[0]" />
				<NcTextField
					v-model="textFields.description"
					:disabled="objectStore.isLoading('catalog')"
					:label="t('opencatalogi', 'Description')"
					maxlength="255"
					:error="!!inputValidation.fieldErrors?.['description']"
					:helperText="
						inputValidation.fieldErrors?.['description']?.[0]
					" />
				<!-- REQ-PTR-003: the same in every language, read-only in another one. -->
				<fieldset
					class="catalog-shared-fields"
					:disabled="editLanguage !== sourceLanguage">
					<NcTextField
						v-model="catalogi.slug"
						:disabled="objectStore.isLoading('catalog')"
						:label="t('opencatalogi', 'Slug*')"
						maxlength="255"
						:error="!!inputValidation.fieldErrors?.['slug']"
						:helperText="
							inputValidation.fieldErrors?.['slug']?.[0]
							|| t(
								'opencatalogi',
								'URL-friendly identifier (e.g., publications, datasets)',
							)
						"
						:placeholder="t('opencatalogi', 'publications')" />
					<NcCheckboxRadioSwitch
						v-model="catalogi.listed"
						:disabled="objectStore.isLoading('catalog')"
						:label="t('opencatalogi', 'Publicly available')">
						{{ t('opencatalogi', 'Publicly available') }}
					</NcCheckboxRadioSwitch>
					<NcSelect
						v-model="selectedOrganization"
						:options="organizationOptions"
						:inputLabel="t('opencatalogi', 'Organization')"
						:disabled="objectStore.isLoading('catalog')" />
					<NcSelect
						v-model="selectedRegisters"
						:options="registerOptions"
						:inputLabel="t('opencatalogi', 'Registers*')"
						:disabled="objectStore.isLoading('catalog')"
						multiple />
					<NcSelect
						v-model="selectedSchemas"
						:options="schemaOptions"
						:inputLabel="t('opencatalogi', 'Schemas*')"
						:disabled="objectStore.isLoading('catalog')"
						:keepOpen="true"
						multiple />
					<NcSelect
						v-model="catalogi.status"
						:options="statusOptions"
						labelAttribute="label"
						:inputLabel="t('opencatalogi', 'Status*')"
						:disabled="objectStore.isLoading('catalog')" />
					<NcCheckboxRadioSwitch
						v-model="catalogi.hasWooSitemap"
						:disabled="objectStore.isLoading('catalog')"
						:label="t('opencatalogi', 'Has Woo Sitemap')">
						{{ t('opencatalogi', 'Requires Woo sitemap') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="catalogi.hasOoapi"
						:disabled="objectStore.isLoading('catalog')"
						:label="t('opencatalogi', 'Has OOAPI 5.0 publication')">
						{{
							t(
								'opencatalogi',
								"Publish this catalog's course/program/offering data via the Open Onderwijs API (OOAPI 5.0)",
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="catalogi.hasDcat"
						:disabled="objectStore.isLoading('catalog')"
						:label="t('opencatalogi', 'Has DCAT feed')"
						data-testid="catalog-has-dcat">
						{{
							t(
								'opencatalogi',
								"Publish this catalog's datasets as a DCAT-AP-NL feed that data.overheid.nl can harvest",
							)
						}}
					</NcCheckboxRadioSwitch>
					<LicencePicker
						v-if="catalogi.hasDcat"
						v-model="catalogi.dcatLicense"
						:inputLabel="t('opencatalogi', 'Licence of the datasets in the feed')"
						:placeholder="t('opencatalogi', 'The instance default licence')"
						:disabled="objectStore.isLoading('catalog')"
						data-testid="catalog-dcat-licence" />
					<NcCheckboxRadioSwitch
						v-model="catalogi.showWithheld"
						:disabled="objectStore.isLoading('catalog')"
						data-testid="catalog-show-withheld">
						{{
							t(
								'opencatalogi',
								'Show which documents of a publication were withheld, and on which grounds',
							)
						}}
					</NcCheckboxRadioSwitch>
					<CatalogStaffAccessFields
						v-if="isAdmin"
						v-model="catalogi.staffAccess"
						:disabled="objectStore.isLoading('catalog')" />
					<CatalogRetentionDefaultsFields
						v-if="isAdmin && storedSlug"
						:catalogSlug="storedSlug"
						:disabled="objectStore.isLoading('catalog')" />
				</fieldset>
			</div>
		</template>
	</CnFormDialog>
</template>

<script>
import {
	CnFormDialog,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import CatalogRetentionDefaultsFields from '../../components/CatalogRetentionDefaultsFields.vue'
import CatalogStaffAccessFields from '../../components/CatalogStaffAccessFields.vue'
import LicencePicker from '../../components/LicencePicker.vue'
import TranslationLanguageSwitch from '../../components/TranslationLanguageSwitch.vue'
import { Catalogi } from '../../entities/index.js'
import {
	catalogFormFromObject,
	catalogOrganizationOption,
	idOptions,
} from '../../services/catalogForm.js'
import {
	outdatedFields,
	publicationLanguagesFrom,
	SOURCE_LANGUAGE,
	takeRequestedEditorLanguage,
	translationItem,
	translationPayload,
} from '../../services/publicationTranslations.js'
import { sortOptionsByLabel } from '../../services/sortOptionsByLabel.js'

/**
 * The form of a catalog that is being added.
 *
 * @return {object} A fresh, empty form.
 */
function emptyCatalogForm() {
	return {
		title: '',
		summary: '',
		description: '',
		slug: '',
		listed: false,
		registers: [],
		schemas: [],
		filters: {},
		status: { id: 'development', label: 'Development' },
		hasWooSitemap: false,
		hasOoapi: false,
		hasDcat: false,
		dcatLicense: '',
		showWithheld: false,
		staffAccess: null,
	}
}

/**
 * The ids of every schema the selected registers list, once each.
 *
 * @param {Array<object>} registers The available registers.
 * @param {Array<object>} selectedRegisters The selected register options.
 * @return {Array<number|string>} The schema ids.
 */
function schemaIdsOfSelected(registers, selectedRegisters) {
	const selectedRegisterIds = selectedRegisters.map((register) => register.id)
	return [
		...new Set(
			registers
				.filter((register) => selectedRegisterIds.includes(register.id))
				.flatMap((register) => register.schemas.map((schema) => schema.id)),
		),
	]
}

/**
 * The first schema of each id, in order.
 *
 * availableSchemas holds one entry per (register, schema) pair, so a schema
 * exposed by several selected registers would otherwise appear several times
 * and trigger Vue duplicate-key warnings in NcSelect.
 *
 * @param {Array<object>} schemas The schemas.
 * @return {Array<object>} The schemas, one per id.
 */
function firstOfEachId(schemas) {
	const seen = new Set()
	return schemas.filter((schema) => {
		if (seen.has(schema.id)) return false
		seen.add(schema.id)
		return true
	})
}

/**
 * A schema as a select option, labelled with its register.
 *
 * @param {object} schema The schema.
 * @return {object} The option.
 */
function schemaOption(schema) {
	return {
		id: schema.id,
		label: `${schema.title} (${schema.registerTitle})`,
	}
}

export default {
	name: 'CatalogModal',
	components: {
		CatalogRetentionDefaultsFields,
		LicencePicker,
		TranslationLanguageSwitch,
		CatalogStaffAccessFields,
		CnFormDialog,
		NcTextField,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	data() {
		return {
			catalogi: emptyCatalogForm(),

			selectedOrganization: null,
			selectedRegisters: [],
			selectedSchemas: [],
			hasUpdated: false,
			// publication-translations REQ-PTR-003: the language being edited,
			// the languages offered, the text in that language and the status rows.
			sourceLanguage: SOURCE_LANGUAGE,
			editLanguage: SOURCE_LANGUAGE,
			editLanguages: [SOURCE_LANGUAGE],
			translation: { title: '', summary: '', description: '' },
			translationOpened: { title: '', summary: '', description: '' },
			translationStatus: null,
			translationError: '',
			statusOptions: [
				{ id: 'development', label: 'Development' },
				{ id: 'beta', label: 'Beta' },
				{ id: 'stable', label: 'Stable' },
				{ id: 'obsolete', label: 'Obsolete' },
			],

			// The MDI icon on the dialog's Cancel / Close button.
			dismissIcon: 'Cancel',
		}
	},

	computed: {
		/**
		 * The title, summary and description the text fields edit: the
		 * catalogue's own in Dutch, the translation in another language.
		 *
		 * @return {object} The fields
		 * @spec openspec/changes/archive/2026-11-24-publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		textFields() {
			return this.editLanguage === SOURCE_LANGUAGE
				? this.catalogi
				: this.translation
		},

		/**
		 * The translated fields the editor changed since the language was opened.
		 *
		 * @return {object} The changed fields
		 * @spec openspec/changes/archive/2026-11-24-publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		translationChanges() {
			const changes = {}
			for (const [field, value] of Object.entries(this.translation)) {
				if (value !== this.translationOpened[field]) {
					changes[field] = value
				}
			}
			return changes
		},

		/**
		 * The fields whose Dutch changed since they were translated.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/archive/2026-11-24-publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		editLanguageOutdated() {
			return outdatedFields(this.translationStatus, this.editLanguage)
		},

		isEdit() {
			return !!objectStore.getActiveObject('catalog')
		},

		/**
		 * The schema CnFormDialog is given: the title only. The fields are the
		 * #form slot's own, validated by the Catalogi entity (inputValidation).
		 *
		 * @return {object} The dialog schema.
		 * @spec openspec/changes/archive/2026-11-25-catalogs/tasks.md#task-9
		 */
		dialogSchema() {
			return { title: t('opencatalogi', 'Catalog'), properties: {} }
		},

		/**
		 * The slug the catalogue is saved under, which keys its retention defaults (RET-004).
		 *
		 * @spec openspec/changes/archive/2026-11-24-capability-rows-close-gaps/tasks.md#task-3.1
		 */
		storedSlug() {
			return objectStore.getActiveObject('catalog')?.slug || ''
		},

		/** @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1 */
		organizationOptions() {
			return objectStore
				.getCollection('organization')
				.results.map((organization) => ({
					id: organization.id,
					label: organization.name,
				}))
		},

		/** @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1 */
		registerOptions() {
			return objectStore.availableRegisters.map((register) => ({
				id: register.id,
				label: register.title,
			}))
		},

		/** @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1 */
		schemaOptions() {
			// The schemas of the selected registers, without the ones already selected.
			const availableSchemaIds = schemaIdsOfSelected(
				objectStore.availableRegisters,
				this.selectedRegisters,
			)
			const selectedSchemaIds = this.selectedSchemas.map((schema) => schema.id)

			return sortOptionsByLabel(
				firstOfEachId(
					objectStore.availableSchemas.filter(
						(schema) =>
							availableSchemaIds.includes(schema.id)
							&& !selectedSchemaIds.includes(schema.id),
					),
				).map(schemaOption),
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1 */
		inputValidation() {
			const result = this.catalogItem().validate()

			return {
				success: result.success,
				errorMessages:
					result?.error?.issues.map(
						(issue) => `${issue.path.join('.')}: ${issue.message}`,
					) || [],

				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1 */
	updated() {
		if (navigationStore.modal !== 'catalog' || this.hasUpdated) {
			return
		}
		this.hasUpdated = true
		if (!this.isEdit) {
			return
		}
		this.fillFromCatalog(objectStore.getActiveObject('catalog'))
		this.loadTranslationContext(takeRequestedEditorLanguage())
	},

	methods: {
		/**
		 * Fill the form from a catalog that is being edited.
		 *
		 * @param {object} activeCatalog The catalog.
		 * @return {void}
		 */
		fillFromCatalog(activeCatalog) {
			this.catalogi = catalogFormFromObject(activeCatalog, this.statusOptions)
			this.selectedOrganization = catalogOrganizationOption(
				activeCatalog,
				objectStore.getCollection('organization').results,
			)
			this.selectedRegisters = idOptions(
				activeCatalog.registers,
				objectStore.availableRegisters,
			)
			this.selectedSchemas = idOptions(
				activeCatalog.schemas,
				objectStore.availableSchemas,
			)
		},

		/**
		 * The form as a Catalogi entity: the selected options as their ids and the
		 * status as its id.
		 *
		 * @return {Catalogi} The catalog.
		 */
		catalogItem() {
			const registers = this.selectedRegisters.map((register) => register.id)
			const schemas = this.selectedSchemas.map((schema) => schema.id)

			return new Catalogi({
				...this.catalogi,
				status: this.statusId(),
				organization: this.selectedOrganization?.id,
				registers,
				schemas,
				filters: this.catalogi.filters || {},
			})
		},

		/**
		 * The form's status as its id: the option's id, or the text lowercased.
		 *
		 * @return {string} The status id.
		 */
		statusId() {
			return typeof this.catalogi.status === 'object'
				? this.catalogi.status.id
				: this.catalogi.status.toLowerCase()
		},

		/**
		 * Show the outcome in the dialog; on success it closes itself after two seconds.
		 *
		 * @param {object} result `{ success: true }` or `{ error: string }`.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-catalogs/tasks.md#task-9
		 */
		showResult(result) {
			this.$refs.formDialog?.setResult(result)
		},

		/**
		 * The message of a failed save: the store's error, else a sentence for the mode.
		 *
		 * @param {Error|undefined} error The rejection.
		 * @return {string} The message.
		 * @spec openspec/changes/archive/2026-11-25-catalogs/tasks.md#task-9
		 */
		saveErrorMessage(error) {
			if (error?.message) {
				return error.message
			}
			return this.isEdit
				? t('opencatalogi', 'Something went wrong while editing the catalog')
				: t('opencatalogi', 'Something went wrong while adding the catalog')
		},

		/** @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-1 */
		closeModal() {
			navigationStore.setModal(false)
			this.hasUpdated = false
			this.catalogi = emptyCatalogForm()
			this.selectedOrganization = null
			this.selectedRegisters = []
			this.selectedSchemas = []
			// Reset the object store state
			objectStore.setState('catalog', { success: null, error: null })
		},

		/**
		 * The OpenRegister object URL of the catalogue being edited.
		 *
		 * @return {string} The URL
		 * @spec openspec/changes/archive/2026-11-24-publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		catalogObjectUrl() {
			const active = objectStore.getActiveObject('catalog') || {}
			const self = active['@self'] || {}
			return `/index.php/apps/openregister/api/objects/${self.register}/${self.schema}/${self.id || active.id}`
		},

		/**
		 * Read the languages offered and the catalogue's translation status,
		 * then open the language asked for. A failed read keeps it in Dutch.
		 *
		 * @param {string} language The language to open in.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		async loadTranslationContext(language) {
			this.editLanguage = SOURCE_LANGUAGE
			this.translationError = ''
			try {
				const settings = await (
					await fetch(generateUrl('/apps/opencatalogi/api/settings'))
				).json()
				this.editLanguages = publicationLanguagesFrom(
					settings?.configuration?.publication_languages,
				)
				const active = objectStore.getActiveObject('catalog') || {}
				const self = active['@self'] || {}
				const statusUrl = generateUrl(
					'/apps/openregister/api/translations/object/{id}',
					{ id: self.id || active.id },
				)
				this.translationStatus = await (
					await fetch(
						statusUrl + '?schema=' + encodeURIComponent(self.schema),
					)
				).json()
			} catch {
				this.editLanguages = [SOURCE_LANGUAGE]
				this.translationStatus = null
			}
			if (
				language !== SOURCE_LANGUAGE
				&& this.editLanguages.includes(language)
			) {
				await this.setEditLanguage(language)
			}
		},

		/**
		 * Switch the text fields to a language, read with every translation.
		 *
		 * @param {string} language The language.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		async setEditLanguage(language) {
			this.editLanguage = language
			if (language === SOURCE_LANGUAGE) {
				return
			}
			const all = await fetch(this.catalogObjectUrl() + '?_translations=all')
				.then((response) => response.json())
				.catch(() => null)
			const item = translationItem(this.catalogi, all, language)
			this.translation = {
				title: item.title,
				summary: item.summary,
				description: item.description,
			}
			this.translationOpened = { ...this.translation }
		},

		/**
		 * Save the changed fields of the language being edited as its translation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		async saveTranslation() {
			this.translationError = ''
			const stored = await objectStore.saveObjectTranslation(
				this.catalogObjectUrl(),
				{
					fields: translationPayload(this.translationChanges),
					language: this.editLanguage,
				},
			)
			if (!stored) {
				this.translationError = t(
					'opencatalogi',
					'The translation could not be saved. Try again.',
				)
				// Keep the form open so the editor can try again.
				this.$refs.formDialog?.setValidationErrors({})
				return
			}
			this.translationOpened = { ...this.translation }
			this.showResult({ success: true })
		},

		/**
		 * Create or update the catalog via the object store; in another
		 * language than Dutch, save the translation only.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/catalogs/spec.md
		 * @spec openspec/changes/archive/2026-11-25-catalogs/tasks.md#task-9
		 */
		saveCatalog() {
			if (this.isEdit && this.editLanguage !== SOURCE_LANGUAGE) {
				return this.saveTranslation()
			}
			const catalogiItem = this.catalogItem()

			let write
			if (this.isEdit) {
				write = objectStore.updateObject('catalog', catalogiItem.id, catalogiItem)
			} else {
				delete catalogiItem.id
				write = objectStore.createObject('catalog', catalogiItem)
			}

			return write
				.then(() => this.showResult({ success: true }))
				.catch((error) => this.showResult({ error: this.saveErrorMessage(error) }))
		},
	},
}
</script>

<style>
.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}

.success {
	color: green;
}
</style>

<style scoped>
.form-group {
	display: flex;
	flex-direction: column;
}

.catalog-shared-fields {
	display: flex;
	flex-direction: column;
	min-width: 0;
	margin: 0;
	padding: 0;
	border: 0;
}
</style>
