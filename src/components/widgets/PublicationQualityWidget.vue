<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="publication-quality" data-testid="publication-quality">
		<NcLoadingIcon v-if="loading" :size="20" />
		<NcNoteCard v-else-if="failed" type="warning">
			<p>{{ t('opencatalogi', 'The metadata quality could not be loaded') }}</p>
		</NcNoteCard>
		<template v-else>
			<p class="publication-quality__score" data-testid="publication-quality-score">
				{{ t('opencatalogi', '{score} of 100', { score: total }) }}
			</p>
			<template v-if="missingNames.length > 0">
				<h4 class="publication-quality__heading">
					{{ t('opencatalogi', 'Missing metadata') }}
				</h4>
				<ul class="publication-quality__missing" data-testid="publication-quality-missing">
					<li v-for="name in missingNames" :key="name">
						{{ name }}
					</li>
				</ul>
			</template>
			<p v-else>
				{{ t('opencatalogi', 'Nothing is missing') }}
			</p>
			<p class="publication-quality__hint">
				{{ t('opencatalogi', 'A low score never stops publishing.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { fetchPublicationQuality, missingFieldNames } from '../../services/publicationQuality.js'
import { injectedObjectContext } from './objectContext.js'

/**
 * PublicationQualityWidget: the metadata-quality score of the dataset this
 * publication renders as in its catalogue's DCAT feed, and the fields it still
 * misses, shown before it is published. Advisory only (PQM-001, PQM-004).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */
export default {
	name: 'PublicationQualityWidget',
	components: { NcLoadingIcon, NcNoteCard },
	inject: {
		cnObjectContext: { default: null },
		cnDetailObjectContext: { default: null },
	},

	props: {
		objectId: { type: String, default: '' },
		content: { type: Object, default: () => ({}) },
	},

	data() {
		return { loading: true, failed: false, total: 0, missing: [] }
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

		/** @spec exclude presentational widget adapter; the catalogue the page was opened in. */
		catalogSlug() {
			return this.content.catalogSlug || this.$route?.params?.catalogSlug || ''
		},

		/** @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5 */
		missingNames() {
			return missingFieldNames(this.missing, t)
		},
	},

	watch: {
		/** @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5 */
		resolvedObjectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the score.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
		 */
		async load() {
			if (!this.resolvedObjectId || !this.catalogSlug) {
				this.loading = false
				this.failed = true
				return
			}
			this.loading = true
			this.failed = false
			try {
				const score = await fetchPublicationQuality(this.catalogSlug, this.resolvedObjectId)
				this.total = score.total
				this.missing = score.missing
			} catch {
				this.failed = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.publication-quality__score {
	font-size: var(--default-font-size);
	font-weight: bold;
}

.publication-quality__heading {
	margin-block: 8px 4px;
}

.publication-quality__missing {
	padding-inline-start: 20px;
	list-style: disc;
}

.publication-quality__hint {
	color: var(--color-text-maxcontrast);
	margin-block-start: 8px;
}
</style>
