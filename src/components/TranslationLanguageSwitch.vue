<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div
		class="translation-language-switch"
		data-testid="translation-language-switch">
		<div
			class="translation-language-switch__options"
			role="radiogroup"
			:aria-label="t('opencatalogi', 'Language')">
			<span class="translation-language-switch__label" aria-hidden="true">
				{{ t('opencatalogi', 'Language') }}
			</span>
			<NcButton
				v-for="option in options"
				:key="option.id"
				role="radio"
				:aria-checked="option.id === modelValue ? 'true' : 'false'"
				:variant="option.id === modelValue ? 'primary' : 'secondary'"
				:disabled="disabled"
				:data-testid="'translation-language-' + option.id"
				@click="choose(option.id)">
				{{ option.label }}
			</NcButton>
		</div>
		<p class="translation-language-switch__hint">
			{{
				t(
					'opencatalogi',
					'Title, summary and description are translatable. Empty fields fall back to Dutch.',
				)
			}}
		</p>
		<p
			v-if="modelValue !== sourceLanguage"
			class="translation-language-switch__hint"
			data-testid="translation-same-everywhere">
			{{
				t(
					'opencatalogi',
					'The other fields are the same in every language and read-only here.',
				)
			}}
		</p>
		<NcNoteCard
			v-if="modelValue !== sourceLanguage && outdatedNames"
			type="warning"
			data-testid="translation-outdated">
			{{
				t(
					'opencatalogi',
					'Dutch changed since this was translated: {fields}',
					{ fields: outdatedNames },
				)
			}}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	languageName,
	SOURCE_LANGUAGE,
} from '../services/publicationTranslations.js'

/**
 * The language switch above the fields of a publication or catalogue edit
 * page: Dutch as the source and every language the administrator enabled
 * (publication-translations REQ-PTR-003, design D4).
 *
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
 */
export default {
	name: 'TranslationLanguageSwitch',
	components: { NcButton, NcNoteCard },

	props: {
		languages: { type: Array, default: () => [SOURCE_LANGUAGE] },
		modelValue: { type: String, default: SOURCE_LANGUAGE },
		outdated: { type: Array, default: () => [] },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return { sourceLanguage: SOURCE_LANGUAGE }
	},

	computed: {
		/**
		 * One option per language, Dutch named as the source.
		 *
		 * @return {Array<{id: string, label: string}>} The options
		 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		options() {
			const locale = document.documentElement.lang || 'en'
			return this.languages.map((tag) => ({
				id: tag,
				label:
					tag === SOURCE_LANGUAGE
						? this.t('opencatalogi', '{language}, source', {
								language: languageName(tag, locale),
							})
						: languageName(tag, locale),
			}))
		},

		/**
		 * The outdated fields as one list.
		 *
		 * @return {string} The field names, or ''
		 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		outdatedNames() {
			const names = {
				title: this.t('opencatalogi', 'Title').toLocaleLowerCase(),
				summary: this.t('opencatalogi', 'Summary').toLocaleLowerCase(),
				description: this.t(
					'opencatalogi',
					'Description',
				).toLocaleLowerCase(),
			}
			return this.outdated.map((field) => names[field] || field).join(', ')
		},
	},

	methods: {
		/**
		 * Switch to a language.
		 *
		 * @param {string} tag The language.
		 * @return {void}
		 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-an-editor-writes-a-translation-on-the-edit-page-req-ptr-003
		 */
		choose(tag) {
			if (tag !== this.modelValue) {
				this.$emit('update:modelValue', tag)
			}
		},
	},
}
</script>

<style scoped>
.translation-language-switch {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.translation-language-switch__options {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.translation-language-switch__label {
	font-weight: bold;
}

.translation-language-switch__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
