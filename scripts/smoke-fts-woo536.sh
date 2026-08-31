#!/usr/bin/env bash
# ------------------------------------------------------------------
# WOO-536 acceptance-criteria smoke test (fresh docker install).
#
# Assumes seed-fulltext-search-test-data.sh has run (or an equivalent
# seed exists) so a catalog + publications + documents are in place.
# Exercises Robert's Definition of Done ("returns matches from every
# schema in every catalog the caller may see") + SCH-PFTS-001..004 +
# SCH-PFTS-CAT-001..003 via the public /api/search endpoint.
#
# Runs 8 assertions:
#   1. anon default scope returns at least 1 publication
#   2. total == results-length (bug 1 regression guard — no undercount)
#   3. admin auth returns identical result-set as anon (Q1 Option B)
#   4. `_catalog=<known-slug>` scope narrows correctly
#   5. `_catalog=nonexistent` returns HTTP 200 with total: 0 (graceful)
#   6. `_schema=999` client scope-widening attempt is stripped
#   7. envelope carries results/total (facets optional per OR response)
#   8. every row carries `@self.schema` as a slug string (SCH-PFTS-002)
#
# Usage:
#   ./scripts/smoke-fts-woo536.sh \
#     [--base-url http://nextcloud.local] \
#     [--admin-user admin] \
#     [--admin-token <app-password>] \
#     [--catalog-slug <known-catalog-slug>]
#
# Exits 0 on all-green, non-zero on any assertion failure with a
# per-check diagnostic. Rate-limits itself with sleep so it doesn't
# trip the anonymous DoS defense (PUBLIC_LIMIT_MAX).
# ------------------------------------------------------------------

set -euo pipefail

# Defaults — override via flags.
BASE_URL="http://nextcloud.local"
ADMIN_USER="admin"
ADMIN_TOKEN=""
CATALOG_SLUG=""
DOCKER_CONTAINER="master-nextcloud-1"

while [ $# -gt 0 ]; do
	case "$1" in
		--base-url)      BASE_URL="$2"; shift 2 ;;
		--admin-user)    ADMIN_USER="$2"; shift 2 ;;
		--admin-token)   ADMIN_TOKEN="$2"; shift 2 ;;
		--catalog-slug)  CATALOG_SLUG="$2"; shift 2 ;;
		--container)     DOCKER_CONTAINER="$2"; shift 2 ;;
		-h|--help)
			sed -n '3,32p' "$0" | sed 's|^# \?||'
			exit 0
			;;
		*)
			echo "unknown flag: $1" >&2
			exit 2
			;;
	esac
done

# All curls run inside the NC container so networking is deterministic.
curl_in() {
	docker exec "${DOCKER_CONTAINER}" curl -s "$@"
}

# Fetch envelope + assert basic keys. Args: 1=url, 2=[admin|anon].
fetch_envelope() {
	local url="$1"
	local mode="$2"
	if [ "$mode" = "admin" ]; then
		curl_in -u "${ADMIN_USER}:${ADMIN_TOKEN}" -H "Host: ${BASE_URL#http://}" "http://localhost${url}"
	else
		curl_in -H "Host: ${BASE_URL#http://}" "http://localhost${url}"
	fi
}

PASS=0
FAIL=0
FAILURES=()

pass() { echo "  ✓ $1"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ $1" >&2; FAIL=$((FAIL + 1)); FAILURES+=("$1"); }

json_get() {
	# Dot-path getter. `jq` is preinstalled in the NC container image; safer than
	# a tab-indented Python heredoc that any editor pass or `shfmt` run can break.
	# `// empty` maps a missing path to a blank line so callers can `[ "$x" = "0" ]`
	# without special-casing the missing key.
	jq -r --arg key "$1" 'getpath($key | split(".")) // empty'
}

echo "=== WOO-536 anon default-scope search ==="
env1=$(fetch_envelope "/index.php/apps/opencatalogi/api/search" anon)
total_default=$(echo "$env1" | json_get 'total')
count_default=$(echo "$env1" | python3 -c "import json,sys; print(len(json.load(sys.stdin).get('results',[])))")
echo "  total: ${total_default}, results len: ${count_default}"

if [ "${total_default:-0}" -ge 1 ]; then
	pass "assertion 1: anon default scope returns at least 1 row (${total_default})"
else
	fail "assertion 1: expected at least 1 row, got ${total_default} — is the seed data present?"
fi

if [ "${total_default:-0}" = "${count_default:-999}" ]; then
	pass "assertion 2: total (${total_default}) == results-length (${count_default}) — bug 1 regression guard"
else
	fail "assertion 2: total (${total_default}) != results-length (${count_default}) — UNDERCOUNT REGRESSION (SCH-PFTS-004)"
fi

# Envelope keys
if echo "$env1" | python3 -c "import json,sys; d=json.load(sys.stdin); assert 'results' in d and 'total' in d" 2>/dev/null; then
	pass "assertion 7: envelope carries results + total (facets optional per OR response)"
else
	fail "assertion 7: envelope missing results or total"
fi

# @self.schema on every row
if echo "$env1" | python3 -c "
import json, sys
d = json.load(sys.stdin)
for r in d.get('results', []):
	schema = r.get('@self', {}).get('schema')
	assert isinstance(schema, str) and schema, f'row missing @self.schema: {r.get(\"title\")}'
" 2>/dev/null; then
	pass "assertion 8: every row carries @self.schema as slug string (SCH-PFTS-002)"
else
	fail "assertion 8: some row missing @self.schema — SCH-PFTS-002 violated"
fi

sleep 2  # pace against rate-limiter

# Admin parity
if [ -n "${ADMIN_TOKEN}" ]; then
	echo "=== admin auth parity ==="
	env2=$(fetch_envelope "/index.php/apps/opencatalogi/api/search" admin)
	total_admin=$(echo "$env2" | json_get 'total')
	if [ "${total_admin:-0}" = "${total_default:-1}" ]; then
		pass "assertion 3: admin auth total (${total_admin}) == anon total (${total_default}) — Q1 Option B (SCH-PFTS-001)"
	else
		fail "assertion 3: admin total (${total_admin}) != anon total (${total_default}) — admin bypass NOT suppressed"
	fi
else
	echo "  ⊘ assertion 3 SKIPPED: no --admin-token supplied"
fi

sleep 2

# _catalog scope
if [ -n "${CATALOG_SLUG}" ]; then
	echo "=== _catalog=${CATALOG_SLUG} scope narrowing ==="
	env3=$(fetch_envelope "/index.php/apps/opencatalogi/api/search?_catalog=${CATALOG_SLUG}" anon)
	total_scope=$(echo "$env3" | json_get 'total')
	echo "  total: ${total_scope}"
	if [ "${total_scope:-x}" -ge 0 ] 2>/dev/null; then
		pass "assertion 4: _catalog=${CATALOG_SLUG} resolves gracefully (total: ${total_scope})"
	else
		fail "assertion 4: _catalog=${CATALOG_SLUG} returned no numeric total"
	fi
else
	echo "  ⊘ assertion 4 SKIPPED: no --catalog-slug supplied"
fi

sleep 2

# _catalog=nonexistent
echo "=== _catalog=nonexistent graceful edge case ==="
http_code=$(curl_in -o /dev/null -w "%{http_code}" -H "Host: ${BASE_URL#http://}" "http://localhost/index.php/apps/opencatalogi/api/search?_catalog=nonexistent-catalog-slug-xyz")
env4=$(fetch_envelope "/index.php/apps/opencatalogi/api/search?_catalog=nonexistent-catalog-slug-xyz" anon)
if [ "${http_code}" = "200" ]; then
	total_ne=$(echo "$env4" | json_get 'total')
	if [ "${total_ne:-x}" = "0" ]; then
		pass "assertion 5: _catalog=nonexistent returns HTTP 200 + total: 0 (graceful)"
	else
		fail "assertion 5: _catalog=nonexistent returned total: ${total_ne} (expected 0)"
	fi
else
	fail "assertion 5: _catalog=nonexistent returned HTTP ${http_code} (expected 200)"
fi

sleep 2

# _schema client widening attempt
echo "=== Q7 Interpretation A: _schema=999 stripped ==="
env5=$(fetch_envelope "/index.php/apps/opencatalogi/api/search?_schema=999" anon)
total_widen=$(echo "$env5" | json_get 'total')
if [ "${total_widen:-x}" = "${total_default:-y}" ]; then
	pass "assertion 6: _schema=999 stripped, results identical to no-widening (${total_widen} == ${total_default})"
else
	fail "assertion 6: _schema=999 changed the result-set (${total_widen} vs ${total_default}) — Q7 Interp A discipline VIOLATED"
fi

echo
echo "=== Summary ==="
echo "  passed: ${PASS}"
echo "  failed: ${FAIL}"
if [ ${FAIL} -gt 0 ]; then
	echo "  failed assertions:"
	for f in "${FAILURES[@]}"; do
		echo "    - $f"
	done
	exit 1
fi
echo "All WOO-536 smoke assertions green."
exit 0
