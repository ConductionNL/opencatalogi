#!/usr/bin/env node
/* eslint-disable jsdoc/require-param */
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * Candidate unwrapped-string detector.
 *
 * Scans .vue files for string literals that LOOK like user-visible prose but
 * are not wrapped in t('<app>', '...'). Where check-l10n.js's UNWRAPPED check
 * only matches strings that already exist as keys in en.js (a tiny slice),
 * this script flags any literal that passes a "looks like prose" heuristic.
 *
 * High-recall by design — expect false positives. Each hit is reported with
 * file:line:col and the surrounding context (text node vs. attribute name)
 * so a human can audit. False positives are managed by hand on review, not
 * by tightening the heuristic to the point of missing real strings.
 *
 * Usage:
 *   node scripts/find-unwrapped.js                  # template only, default
 *   node scripts/find-unwrapped.js --include-script # also scan <script> blocks
 *   node scripts/find-unwrapped.js --json           # machine-readable output
 *   node scripts/find-unwrapped.js --min-length=4   # skip shorter literals
 *   node scripts/find-unwrapped.js path/to/file.vue # restrict to a path/glob root
 *
 * Exit code: 0 if no candidates found, 1 if any found (so CI can gate).
 */

const fs = require('fs')
const path = require('path')

const { walk } = require('./lib/l10n.js')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')

// Attributes whose values render to user-visible text in Nextcloud Vue + this
// codebase. These are ALWAYS-prose attrs: a literal value here is almost
// certainly a candidate, regardless of whether it has a space or capital.
//
// This list is intentionally generous; tighten only after seeing the false
// positives in practice.
const PROSE_ATTRS = new Set([
	'label', 'title', 'placeholder', 'aria-label', 'aria-description',
	'name', // NcActionCaption / NcActionInput etc. render this
	'text', 'tooltip', 'subtitle', 'description', 'heading', 'header',
	'caption', 'message', 'hint',
	'entity-label', 'back-route',
	'input-label', 'menu-name', 'item-text',
	'error-message', 'helper-text', 'success-message',
	'empty-content-name', 'empty-content-description',
	'submit-button-text', 'cancel-button-text', 'confirm-button-text',
	'button-text', 'accept-label', 'dismiss-label',
	'no-options-text', 'loading-text', 'loading-label',
	'select-label', 'deselect-label', 'selected-label',
	'open-direction-text', 'tag-placeholder', 'placeholder-multiple',
	'aria-labelled-by',
])

// Attribute names that take prose only when the attribute starts with a
// capital — `name` for instance is "prose" when used on NcActionCaption but
// "technical" on a form input. We can't tell which without parsing components,
// so we let `name` through and rely on the prose-shape filter to weed out
// `name="sortDirection"`, `name="email"`, etc.

// Tag names whose default-slot text content is decorative-only and should
// NOT be flagged. Currently empty — Nextcloud's components mostly render slot
// text, so default-slot text is almost always prose.
const SKIP_TAGS_FOR_TEXT = new Set([
	// e.g. 'style', 'script' — but those don't appear inside <template> anyway
])

// ---------- CLI ----------

function parseFlags(argv) {
	const flags = {}
	const positionals = []
	for (const a of argv) {
		if (a.startsWith('--')) {
			const eq = a.indexOf('=')
			if (eq === -1) {
				flags[a.slice(2)] = true
			} else {
				flags[a.slice(2, eq)] = a.slice(eq + 1)
			}
		} else {
			positionals.push(a)
		}
	}
	return { flags, positionals }
}

const { flags, positionals } = parseFlags(process.argv.slice(2))

if (flags.help || flags.h) {
	console.log(fs.readFileSync(__filename, 'utf8').split('\n').slice(5, 28).join('\n'))
	process.exit(0)
}

const includeScript = !!flags['include-script']
const asJson = !!flags.json
const minLength = flags['min-length'] ? Math.max(1, parseInt(flags['min-length'], 10)) : 2

// ---------- helpers ----------

function rel(p) {
	return path.relative(ROOT, p)
}

/**
 * "Looks like user-visible prose" filter. Used for plain text nodes and for
 * attribute values on attributes whose name is NOT in PROSE_ATTRS (where we
 * need extra evidence the value is prose).
 */
function looksLikeProse(s, { strictForGenericContext = false } = {}) {
	if (!s) return false
	const t = s.trim()
	if (t.length < minLength) return false
	if (!/[A-Za-z]/.test(t)) return false // no letters → not prose

	// All-uppercase short tokens like HTTP, API, UUID — usually identifiers.
	if (t.length <= 6 && t === t.toUpperCase() && !/\s/.test(t)) return false

	// Pure technical patterns to exclude.
	if (/^https?:\/\//i.test(t)) return false // URL
	if (/^[a-z]+:[\w./-]+/i.test(t)) return false // namespaced (e.g. "icon:foo")
	if (/^#[0-9a-f]{3,8}$/i.test(t)) return false // hex color
	if (/^[\w.-]+\.(vue|js|ts|css|scss|json|svg|png|jpg|jpeg|gif)$/i.test(t)) return false // file path
	if (/^\$?[a-zA-Z_][\w-]*\.[\w.-]+$/.test(t) && !/\s/.test(t)) return false // foo.bar identifier
	if (/^\d+(\.\d+)?(px|rem|em|%|vh|vw|s|ms)$/i.test(t)) return false // CSS dim
	if (/^v-[\w-]+$/.test(t)) return false // vue directive name
	if (/^[\w-]+\([\w\s,'"]*\)$/.test(t)) return false // function call literal

	// Single-token boolean-ish / common code values.
	if (!/\s/.test(t)) {
		// One word. Keep it only if it looks like an English word (capital +
		// lowercase, e.g. "Concept", "Published"). Reject snake_case, kebab-case,
		// and lowercase identifiers like "title", "name", "asc".
		if (/[_]/.test(t)) return false
		if (/^[a-z][\w-]*$/.test(t)) {
			// If we're in a generic context (not a known prose attr), be strict:
			// require at least 4 letters AND not match common code tokens.
			if (strictForGenericContext) return false
			const codeTokens = new Set([
				'true', 'false', 'null', 'undefined', 'asc', 'desc',
				'lg', 'md', 'sm', 'xs', 'xl',
				'left', 'right', 'top', 'bottom', 'center', 'middle',
				'auto', 'none', 'block', 'inline', 'flex', 'grid',
				'primary', 'secondary', 'success', 'warning', 'error', 'info',
			])
			if (codeTokens.has(t.toLowerCase())) return false
			return true
		}
		// A single capitalized word like "Concept" or "Themes" — accept.
		if (/^[A-Z][a-z]+$/.test(t)) return true
		// CamelCase like "BackRoute" — likely an identifier; reject.
		if (/^[A-Z][a-z]+([A-Z][a-z]+)+$/.test(t)) return false
		// Mixed-case / has digits / has uppercase tail — reject.
		return false
	}

	// Multi-word. Almost always prose, but skip a few obvious technical shapes.
	if (/^[a-z][\w-]*\s+[a-z][\w-]*$/.test(t) && t.length < 6) return false // tiny lowercase pair
	return true
}

/**
 * Find the root <template>...</template> block in a .vue file. Vue uses nested
 * <template #slot> elements, so a non-greedy regex picks the wrong closing tag.
 * Walk forward counting <template> opens and </template> closes from the first
 * <template> at depth 0.
 *
 * Returns { start, end } as character offsets of the inner contents (exclusive
 * of the surrounding <template>...</template> tags), or null if not found.
 */
function findRootTemplate(text) {
	const openRe = /<template\b[^>]*>/g
	const closeTag = '</template>'
	const openMatch = openRe.exec(text)
	if (!openMatch) return null
	const start = openMatch.index + openMatch[0].length

	let depth = 1
	let i = start
	const innerOpen = /<template\b[^>]*>/g
	while (depth > 0 && i < text.length) {
		innerOpen.lastIndex = i
		const nextOpen = innerOpen.exec(text)
		const nextClose = text.indexOf(closeTag, i)
		if (nextClose === -1) return null
		if (nextOpen && nextOpen.index < nextClose) {
			depth++
			i = nextOpen.index + nextOpen[0].length
		} else {
			depth--
			if (depth === 0) {
				return { start, end: nextClose }
			}
			i = nextClose + closeTag.length
		}
	}
	return null
}

/** Build a map from char position → 1-based (line, column). */
function makePositionResolver(text) {
	const lineStarts = [0]
	for (let i = 0; i < text.length; i++) {
		if (text.charCodeAt(i) === 10) lineStarts.push(i + 1)
	}
	return (pos) => {
		let lo = 0; let hi = lineStarts.length - 1
		while (lo < hi) {
			const mid = (lo + hi + 1) >> 1
			if (lineStarts[mid] <= pos) lo = mid
			else hi = mid - 1
		}
		return { line: lo + 1, column: pos - lineStarts[lo] + 1 }
	}
}

/**
 * Determine whether a character offset in the file is inside a t(…) call.
 * We pre-compute t-call ranges per file rather than checking each candidate
 * individually so we don't re-parse on every literal.
 */
function computeTCallRanges(text, app) {
	const ranges = []
	const escAppName = app.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const re = new RegExp(`\\bt\\s*\\(\\s*(['"])${escAppName}\\1\\s*,`, 'g')
	let m
	while ((m = re.exec(text)) !== null) {
		// Find the matching closing ')'. We track parens but stop at first
		// unbalanced ')' (good enough heuristic — t() args don't usually nest
		// arbitrary parens, but template literals can; close enough).
		let depth = 1
		let i = re.lastIndex
		// Skip past the second arg's opening sequence; but we just need to find
		// the closing paren of t(.
		while (i < text.length && depth > 0) {
			const c = text[i]
			if (c === '(') depth++
			else if (c === ')') depth--
			else if (c === '\'' || c === '"' || c === '`') {
				// Skip string contents.
				const quote = c
				i++
				while (i < text.length && text[i] !== quote) {
					if (text[i] === '\\') i += 2
					else i++
				}
			}
			i++
		}
		ranges.push([m.index, i])
	}
	return ranges
}

function isInsideRange(pos, ranges) {
	for (const [start, end] of ranges) {
		if (pos >= start && pos < end) return true
	}
	return false
}

// ---------- scanners ----------

/**
 * Scan a .vue <template> block for unwrapped candidates.
 *
 * Returns hits with absolute character positions (relative to the whole file)
 * so the caller can resolve to line/column once.
 *
 * Strategy: walk the template once tracking "inside-tag" vs "outside-tag"
 * state. Inside a tag, scan for attribute values (and emit attr-kind hits for
 * PROSE_ATTRS only). Outside a tag, accumulate text-node content and emit
 * text-kind hits. This avoids the trap where naive regexes confuse a `>` in
 * an attribute value (e.g. `v-if="x > 0"`) with a tag close.
 */
function scanTemplate(file, fullText, tplStart, tplEnd, tCallRanges) {
	const hits = []
	const tpl = fullText.slice(tplStart, tplEnd)

	let i = 0
	while (i < tpl.length) {
		const c = tpl[i]
		if (c === '<' && /[a-zA-Z!/]/.test(tpl[i + 1] || '')) {
			// Comment or CDATA — skip.
			if (tpl.startsWith('<!--', i)) {
				const close = tpl.indexOf('-->', i + 4)
				i = close === -1 ? tpl.length : close + 3
				continue
			}
			// Tag. Find matching '>' while skipping '>' inside attribute values.
			const tagStart = i
			let j = i + 1
			let inSingle = false
			let inDouble = false
			while (j < tpl.length) {
				const ch = tpl[j]
				if (!inSingle && !inDouble && ch === '>') break
				if (!inSingle && ch === '"') inDouble = !inDouble
				else if (!inDouble && ch === '\'') inSingle = !inSingle
				j++
			}
			const tagText = tpl.slice(tagStart, j) // "<NcButton attr='...' attr=\"...\""
			scanTagAttrs(tagText, tagStart, tplStart, tCallRanges, hits)
			i = j + 1
		} else {
			// Text-node region: from i up to next '<' that opens a real tag.
			const textStart = i
			while (i < tpl.length && tpl[i] !== '<') i++
			const textEnd = i
			const raw = tpl.slice(textStart, textEnd)
			// Strip {{ ... }} interpolations.
			const stripped = raw.replace(/\{\{[\s\S]*?\}\}/g, ' ')
			const trimmed = stripped.trim()
			if (trimmed) {
				const leadingWs = raw.match(/^\s*/)[0].length
				const absPos = tplStart + textStart + leadingWs
				if (!isInsideRange(absPos, tCallRanges)
					&& looksLikeProse(trimmed, { strictForGenericContext: true })) {
					hits.push({ kind: 'text', value: trimmed, pos: absPos })
				}
			}
		}
	}

	return hits
}

/**
 * Scan one opening-tag's attribute list and push hits for any prose-shaped
 * literals on attributes in PROSE_ATTRS. tagText is the literal "<TagName ..."
 * up to (but not including) the closing '>'.
 */
function scanTagAttrs(tagText, tagStartInTpl, tplStartInFile, tCallRanges, hits) {
	// Drop "<TagName" prefix; keep attribute portion only.
	const m = tagText.match(/^<\s*([a-zA-Z][\w-]*)/)
	if (!m) return
	const tagName = m[1]
	if (SKIP_TAGS_FOR_TEXT.has(tagName.toLowerCase())) return
	const attrSpan = tagText.slice(m[0].length)
	const attrSpanOffset = m[0].length

	const attrRe = /(\s+)((?::|@|v-)?[a-zA-Z_][\w:.-]*)\s*=\s*("([^"]*)"|'([^']*)')/g
	let am
	while ((am = attrRe.exec(attrSpan)) !== null) {
		const rawName = am[2]
		const isBound = rawName.startsWith(':')
		const isEvent = rawName.startsWith('@') || rawName.startsWith('v-on')
		const isDirective = rawName.startsWith('v-') && !isEvent
		if (isEvent || isDirective) continue
		const name = isBound ? rawName.slice(1) : rawName

		// Only consider attributes whose name is in our prose set. Anything else
		// (class, style, icon, rel, sortDirection-style 'name' on form inputs,
		// etc.) produces too much noise without component-level context.
		if (!PROSE_ATTRS.has(name.toLowerCase())) continue

		const value = am[4] !== undefined ? am[4] : am[5]
		if (!value) continue

		let literal = null
		if (isBound) {
			// `:title="'Save'"` etc. — accept only single-literal expressions.
			const v = value.trim()
			const q1 = v.match(/^'([^'\\]*(?:\\.[^'\\]*)*)'$/)
			const q2 = v.match(/^"([^"\\]*(?:\\.[^"\\]*)*)"$/)
			const q3 = v.match(/^`([^`\\]*(?:\\.[^`\\]*)*)`$/)
			if (q1) literal = q1[1]
			else if (q2) literal = q2[1]
			else if (q3) literal = q3[1]
			else continue
		} else {
			literal = value
		}

		if (!looksLikeProse(literal)) continue

		// Absolute position of the value (just past the opening quote).
		const valueOffsetInAttrSpan = attrSpan.indexOf(am[3], am.index) + 1
		const valueOffsetInTag = attrSpanOffset + valueOffsetInAttrSpan
		const absPos = tplStartInFile + tagStartInTpl + valueOffsetInTag
		if (isInsideRange(absPos, tCallRanges)) continue
		hits.push({ kind: `attr ${rawName}`, value: literal, pos: absPos })
	}
}

/**
 * Scan a <script> block for string literals that look like prose. Higher
 * false-positive rate than template scanning; opt-in via --include-script.
 */
function scanScript(file, fullText, scriptStart, scriptEnd, tCallRanges) {
	const hits = []
	const block = fullText.slice(scriptStart, scriptEnd)
	// Find single, double, and template-literal strings.
	// We deliberately skip strings that are arguments to t(...) by checking
	// their absolute position against tCallRanges.
	const strRe = /(['"`])((?:\\.|(?!\1)[^\\])*)\1/g
	let m
	while ((m = strRe.exec(block)) !== null) {
		const literal = m[2]
		// Skip template literals containing ${...} — those are dynamic.
		if (m[1] === '`' && /\$\{/.test(literal)) continue
		const absPos = scriptStart + m.index
		if (isInsideRange(absPos, tCallRanges)) continue
		// Must look like prose AND be substantial enough that it wasn't an enum value.
		if (!looksLikeProse(literal, { strictForGenericContext: true })) continue
		// Skip if the literal IS a single capitalized word and the line looks
		// like an import/export/property name — too noisy.
		hits.push({ kind: 'script-string', value: literal, pos: absPos })
	}
	return hits
}

// ---------- main ----------

function findVueFiles(roots) {
	const files = []
	for (const root of roots) {
		const stat = fs.statSync(root)
		if (stat.isDirectory()) {
			files.push(...walk(root, ['.vue']))
		} else if (root.endsWith('.vue')) {
			files.push(root)
		}
	}
	return files
}

function detectAppName() {
	// Mirror l10n-ai's approach: read l10n/en.js and trust the registered name.
	const enFile = path.join(ROOT, 'l10n', 'en.js')
	if (!fs.existsSync(enFile)) return 'opencatalogi'
	const code = fs.readFileSync(enFile, 'utf8')
	const m = code.match(/OC\.L10N\.register\(\s*(['"])([^'"]+)\1/)
	return m ? m[2] : 'opencatalogi'
}

function main() {
	const roots = positionals.length
		? positionals.map((p) => path.isAbsolute(p) ? p : path.join(process.cwd(), p))
		: [SRC_DIR]
	const files = findVueFiles(roots)
	const app = detectAppName()

	const allHits = []
	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		const tCallRanges = computeTCallRanges(text, app)
		const resolve = makePositionResolver(text)

		// Find the ROOT <template> block. Vue uses nested <template #slot>...</template>
		// elements, so non-greedy regex matching closes at the first inner </template>
		// and misses everything past it. Walk with a depth counter to find the matching
		// close for the outermost template.
		const rootTemplate = findRootTemplate(text)
		if (rootTemplate) {
			const hits = scanTemplate(file, text, rootTemplate.start, rootTemplate.end, tCallRanges)
			for (const h of hits) {
				const { line, column } = resolve(h.pos)
				allHits.push({ file, line, column, ...h })
			}
		}
		if (includeScript) {
			const scriptMatches = [...text.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)]
			for (const sm of scriptMatches) {
				const open = sm[0].indexOf('>') + 1
				const start = sm.index + open
				const end = sm.index + sm[0].length - '</script>'.length
				const hits = scanScript(file, text, start, end, tCallRanges)
				for (const h of hits) {
					const { line, column } = resolve(h.pos)
					allHits.push({ file, line, column, ...h })
				}
			}
		}
	}

	allHits.sort((a, b) =>
		a.file.localeCompare(b.file) || a.line - b.line || a.column - b.column,
	)

	if (asJson) {
		console.log(JSON.stringify(
			allHits.map((h) => ({ file: rel(h.file), line: h.line, column: h.column, kind: h.kind, value: h.value })),
			null,
			2,
		))
	} else {
		for (const h of allHits) {
			console.log(`${rel(h.file)}:${h.line}:${h.column}\t[${h.kind}]\t${JSON.stringify(h.value)}`)
		}
		console.error('') // blank line on stderr separates list from summary
		const fileCount = new Set(allHits.map((h) => h.file)).size
		console.error(`${allHits.length} candidate(s) across ${fileCount} file(s) (heuristic — expect false positives).`)
		console.error('Pass --include-script to also scan <script> blocks. Pass --json for machine-readable output.')
	}

	process.exit(allHits.length ? 1 : 0)
}

main()
