<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publication-languages" data-testid="publication-languages">
		<ul class="publication-languages__list">
			<li
				v-for="row in rows"
				:key="row.language"
				class="publication-languages__row"
				:data-testid="'publication-language-' + row.language">
				<div class="publication-languages__text">
					<span class="publication-languages__name">{{ row.name }}</span>
					<span class="publication-languages__state">{{
						row.summary
					}}</span>
				</div>
				<NcButton
					v-if="!row.source"
					variant="secondary"
					:data-testid="'publication-language-translate-' + row.language"
					@click="translate(row.language)">
					{{ t('opencatalogi', 'Translate in the editor') }}
				</NcButton>
			</li>
		</ul>
		<NcNoteCard
			v-if="failed"
			type="warning"
			data-testid="publication-languages-failed">
			{{ t('opencatalogi', 'The translation status could not be read.') }}
		</NcNoteCard>
		<p class="publication-languages__footnote">
			{{
				t(
					'opencatalogi',
					'The public API answers in the language the visitor asks for and falls back to Dutch.',
				)
			}}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	languageName,
	languageStates,
	languageSummary,
	publicationLanguagesFrom,
	requestEditorLanguage,
	SOURCE_LANGUAGE,
} from '../../services/publicationTranslations.js'
import { navigationStore, objectStore } from '../../store/store.js'
import { injectedObjectContext, schemaSlug } from './objectContext.js'

/**
 * The Languages card of the publication page (publication-translations
 * REQ-PTR-004, design D5): per enabled language whether it is the source,
 * complete, or which fields are missing or outdated, from OpenRegister's
 * per-object translation status, with Translate in the editor.
 */
export default {
	name: 'PublicationLanguagesWidget',
	components: { NcButton, NcNoteCard },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},

	props: {
		register: { type: String, default: '' },
		schema: { type: [String, Object], default: '' },
		objectId: { type: String, default: '' },
		content: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			languages: [SOURCE_LANGUAGE],
			status: null,
			failed: false,
		}
	},

	computed: {
		/** @spec exclude presentational widget adapter; resolves the object context from inject. */
		ctx() {
			return injectedObjectContext(this)
		},

		/** @spec exclude presentational widget adapter; derives the object id. */
		resolvedObjectId() {
			return this.objectId || this.ctx.objectId || this.content.objectId || ''
		},

		/**
		 * One row per enabled language.
		 *
		 * @return {Array<{language: string, name: string, source: boolean, summary: string}>} The rows
		 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
		 */
		rows() {
			const locale = document.documentElement.lang || 'en'
			return languageStates(this.status, this.languages).map((state) => ({
				language: state.language,
				name: languageName(state.language, locale),
				source: state.source,
				summary: languageSummary(state, this.t),
			}))
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/**
			 * Load the languages and the status once the object id is known.
			 *
			 * @param {string} id The publication id.
			 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
			 */
			handler(id) {
				if (id) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Read the enabled languages and the publication's translation status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
		 */
		async load() {
			this.failed = false
			try {
				const { data } = await axios.get(
					generateUrl('/apps/opencatalogi/api/settings'),
				)
				this.languages = publicationLanguagesFrom(
					data?.configuration?.publication_languages,
				)
			} catch {
				this.languages = publicationLanguagesFrom(undefined)
			}

			try {
				const schema = schemaSlug(this.schema || this.ctx.schema)
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/translations/object/{uuid}',
						{ uuid: this.resolvedObjectId },
					),
					{ params: schema ? { schema } : {} },
				)
				this.status = data
			} catch {
				this.status = null
				this.failed = true
			}
		},

		/**
		 * Open the publication's edit page in a language.
		 *
		 * @param {string} language The language.
		 * @return {void}
		 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-publication-page-shows-what-is-translated-req-ptr-004
		 */
		translate(language) {
			requestEditorLanguage(language)
			if (this.ctx.object) {
				objectStore.setActiveObject('publication', this.ctx.object)
			}
			navigationStore.setModal('viewObject')
		},
	},
}
</script>

<style scoped>
.publication-languages {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.publication-languages__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.publication-languages__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.publication-languages__text {
	display: flex;
	flex-direction: column;
}

.publication-languages__name {
	font-weight: bold;
}

.publication-languages__state,
.publication-languages__footnote {
	color: var(--color-text-maxcontrast);
}

.publication-languages__footnote {
	margin: 0;
}
</style>
