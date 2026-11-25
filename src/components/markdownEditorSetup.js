/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The markdown editor with its theme, highlighter and styles, configured once.
 * Loaded on demand by MarkdownEditor.vue: highlight.js and v-md-editor were
 * evaluated on every page boot although only the markdown fields use them.
 *
 * @spec openspec/specs/generic-object-modals/spec.md
 */
import VueMarkdownEditor from '@kangc/v-md-editor'
import enUS from '@kangc/v-md-editor/lib/lang/en-US.js'
import githubTheme from '@kangc/v-md-editor/lib/theme/github.js'
import hljs from 'highlight.js'

import '@kangc/v-md-editor/lib/style/base-editor.css'
import '@kangc/v-md-editor/lib/theme/style/github.css'

VueMarkdownEditor.use(githubTheme, { Hljs: hljs })
VueMarkdownEditor.lang.use('en-US', enUS)

export default VueMarkdownEditor
