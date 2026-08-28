# Modular manifest fragments (ADR-037)

Drop one `*.json` fragment per OpenSpec change here instead of editing the
monolithic `src/manifest.json`. At build time `mergeManifestFragments()` in
`src/main.js` merges every `manifest.d/*.json` (resolved via `require.context`,
sorted by filename) onto the bundled base manifest.

Why: concurrent same-app builds each add their own pages/menu entries to a
disjoint fragment file, so they never collide on the shared manifest monolith.

Merge semantics: `pages` and `menu` arrays from each fragment are concatenated
onto the base manifest's arrays. Other manifest keys are taken from the base.

A fragment looks like:

```json
{
  "pages": [
    { "id": "myPage", "route": "/my-page", "type": "..." }
  ],
  "menu": [
    { "id": "myPage", "label": "My Page" }
  ]
}
```

`_placeholder.json` ships an empty fragment so `require.context` always resolves
the directory; it is a no-op at merge time.
