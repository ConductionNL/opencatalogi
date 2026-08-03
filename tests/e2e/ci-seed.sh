#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Provision OpenCatalogi's OpenRegister register + schemas on a freshly
# installed Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/opencatalogi/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# `occ app:enable opencatalogi` runs the `InitializeSettings` post-migration
# repair step, which is supposed to import `lib/Settings/publication_register.json`
# into OpenRegister. Two things make that unreliable as the sole fresh-install
# path, and BOTH fail silently:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import can be denied outright — and
#      `InitializeSettings::run()` catches `\Exception` and downgrades it to
#      `$output->warning(...)`, explicitly "Non-fatal". `occ app:enable` still
#      exits 0.
#   2. It calls `loadSettings(force: false)`. The non-forced path is
#      version-guarded: it can advance the recorded configuration version
#      WITHOUT applying the register, so a second run then sees "already
#      current" and does nothing either.
#
# Either way the app enables cleanly, the SPA boots, and the register simply
# is not there. The e2e suite's failure mode in that state is a wall of
# `create 14/55 failed: 404` and `seeding a catalog must succeed` — messages
# that point at the fixtures, not at the missing import.
#
# So this script does the import EXPLICITLY through the admin HTTP API (which
# has a real session and passes RBAC), with `force: true` to defeat the
# version guard, and then VERIFIES the register and schemas actually exist.
# A failed provision becomes one loud step failure here instead of ~14
# misleading spec failures later.
#
# It is idempotent: the import is idempotent server-side, and re-running only
# re-verifies.

set -euo pipefail

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step declares no `env:` block, so
# BASE_URL / ADMIN_USER / ADMIN_PASSWORD are NOT exported to it (unlike the
# "Run Playwright tests" step). Accept them if a caller does set them, and fall
# back to the CI runner's own `php -S 0.0.0.0:8080` otherwise.
#
# That fallback is gated on actually being in CI. On a developer box
# `localhost:8080` is the SHARED dev container, and this script performs
# ADMIN WRITES — it must never silently import a register into someone else's
# environment. Off CI, an unset target is a hard error.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target: ${BASE}"

# ── 1. Import the OpenCatalogi configuration ─────────────────────────────────
# `settings#manualImport` is admin-only (no @NoAdminRequired) and
# @NoCSRFRequired, so basic auth is sufficient. `force` is compared with `===
# true`, so it must arrive as a JSON boolean — a form-encoded "true" is the
# string "true" and would be ignored, silently giving us the version-guarded
# path this script exists to bypass.
IMPORT_URL="${BASE}/index.php/apps/opencatalogi/api/settings/import"
echo "[ci-seed] POST ${IMPORT_URL} (force: true)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{"force":true}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] import HTTP ${IMPORT_CODE}"
head -c 2000 "$IMPORT_BODY"; echo

if [ "$IMPORT_CODE" != "200" ]; then
	echo "::error::OpenCatalogi configuration import failed (HTTP ${IMPORT_CODE}). The e2e suite cannot seed catalogs or publications without it."
	exit 1
fi

# ── 2. Verify the register and schemas are actually there ────────────────────
# The import reporting success is not the same as the register existing —
# verify against OpenRegister directly, using the same slugs the e2e fixtures
# resolve by (tests/e2e/workflows/_fixtures.ts).
verify() {
	python3 - "$1" "$2" <<'PY'
import json, sys
path, kind = sys.argv[1], sys.argv[2]
required = {
    'registers': ['publication'],
    'schemas': ['publication', 'catalog', 'organization', 'document'],
}[kind]
with open(path) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present: {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::OpenCatalogi {kind} missing after import: {missing}')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" -o "$REG_BODY"
verify "$REG_BODY" registers

SCH_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=1000" -o "$SCH_BODY"
verify "$SCH_BODY" schemas

echo "[ci-seed] OpenCatalogi register + schemas provisioned."

# ── 3. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080` and does not
# set PHP_CLI_SERVER_WORKERS, so the built-in server runs ONE worker: every
# request the SPA fires on boot is serialised behind the one before it. On top
# of that the first hit pays a cold opcache and the first parse of the webpack
# bundle.
#
# The measured effect is confined to whichever spec happens to run first —
# `catalog-detail-page.spec.ts` blew its 60s test timeout waiting for
# `[data-testid="cn-index-page"]` on attempt 1 and then passed in 9.1s on
# retry, while every later spec ran in 4-7s. Nothing about the assertion was
# wrong; it was measuring server warm-up.
#
# So warm it here, in the environment-preparation step where it belongs. The
# alternative — raising that spec's timeout — would hide the cold start inside
# the assertion instead of removing it, and would keep drifting upward.
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above.
for path in \
	"/index.php/apps/opencatalogi/" \
	"/index.php/apps/opencatalogi/api/settings" \
	"/index.php/apps/opencatalogi/api/catalogi" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/<app>/js/...` on the CI runner,
# `/custom_apps/<app>/js/...` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a 7 MB bundle, so the warm-up
# silently warms nothing.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/opencatalogi/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*opencatalogi-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# Verified against a live instance: with the bundle moved aside, the asset URL
# still returned `200 text/html` (40 KB) — while 10 of 10 UI specs failed.
# The specs are the honest signal; this check just makes the cause loud and
# immediate instead of arriving as a wall of selector timeouts.
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The OpenCatalogi frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."

# ══════════════════════════════════════════════════════════════════════════════
# TEMPORARY POSITIVE CONTROL — REVERTED IMMEDIATELY AFTER THIS RUN.
#
# Placed at the very END, AFTER the bundle gate above has already confirmed the
# bundle is present and served as JavaScript. So the gate passes on a real
# bundle and the specs then run against an app that cannot mount — which
# isolates the question being asked: do the specs actually depend on the
# OpenCatalogi frontend, or would they pass regardless?
#
# If the suite still reports green here, the green from the previous run is
# worthless.
# ══════════════════════════════════════════════════════════════════════════════
CONTROL_JS="apps/opencatalogi/js/opencatalogi-main.js"
if [ -f "$CONTROL_JS" ]; then
	mv "$CONTROL_JS" "${CONTROL_JS}.CONTROL-MOVED"
	echo "::warning::POSITIVE CONTROL ACTIVE — bundle moved aside. The suite MUST now fail."
else
	echo "::error::POSITIVE CONTROL could not run — ${CONTROL_JS} not found from $(pwd)."
	ls -la apps/opencatalogi/js/ 2>&1 | head -20
	exit 1
fi

# (re-trigger: the first push of a NEW branch is skipped by the caller workflow
# guard `github.event.created != true`, so the job never ran.)
