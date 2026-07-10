module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		// ADR-004 (frontend) / ADR-010 (NL Design): no hardcoded colors — use
		// Nextcloud CSS variables so nldesign theming and dark mode work.
		// `var(--x, #fallback)` fallbacks are the one legitimate exception (the
		// literal only applies when an *older* Nextcloud core doesn't define the
		// variable yet) — allow-list those explicitly with a
		// `stylelint-disable-next-line color-no-hex` comment rather than
		// weakening the rule itself, so every exception is visible in review.
		// See openspec/changes/nc-css-vars-color-cleanup/tasks.md#task-4.
		'color-no-hex': true,
	},
}
