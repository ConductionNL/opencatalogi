#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Provision the OpenCatalogi demo rig.
#
#   docker compose -f docker-compose.demo.yml up -d
#   bash docker/demo/provision.sh
#
# Enables the apps in dependency order on both instances, seeds the peer
# catalogue with publications, points the main instance's directory at the
# peer, and syncs. Then VERIFIES the result rather than announcing it.
#
# Idempotent: safe to re-run. Enabling an enabled app is a no-op, the portal
# is seeded by portaliq's install hook behind its own guard, and the peer's
# publications are matched by name before being created.

set -euo pipefail

MAIN=${MAIN:-oc-demo-main}
PEER=${PEER:-oc-demo-peer}
MAIN_PORT=${MAIN_PORT:-8580}
PEER_PORT=${PEER_PORT:-8581}

# Inside the compose network the peer answers to its container name. This is
# the URL the MAIN instance stores as its directory — it must be resolvable
# from inside the container, which `localhost:8581` is not.
PEER_INTERNAL=${PEER_INTERNAL:-http://oc-demo-peer}

red() { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

occ() {
	local container=$1
	shift
	docker exec -u www-data "$container" php occ "$@"
}

# Wait for the Nextcloud installer to finish. `docker compose up` returns as
# soon as the containers START; the official image then installs Nextcloud,
# which takes considerably longer. Running occ before that finishes fails with
# a message about the instance not being installed, which reads like a broken
# rig rather than an impatient script.
wait_for() {
	local container=$1 port=$2 tries=0
	step "Waiting for $container to finish installing"
	until curl -sf "http://localhost:${port}/status.php" | grep -q '"installed":true'; do
		tries=$((tries + 1))
		if [ "$tries" -gt 120 ]; then
			red "$container did not come up within 10 minutes."
			red "Logs: docker logs $container --tail 50"
			exit 1
		fi
		sleep 5
	done
	green "$container is up"
}

wait_for "$MAIN" "$MAIN_PORT"
wait_for "$PEER" "$PEER_PORT"

# ---------------------------------------------------------------------------
# Apps, in dependency order.
#
# OpenRegister FIRST on both. It owns the register/schema machinery every other
# app's install hook writes into; enabling opencatalogi or portaliq before it
# leaves their repair steps warning that OpenRegister is unavailable and
# provisioning nothing — an install that reports success and produces an empty
# instance.
# ---------------------------------------------------------------------------
step "Enabling apps on the peer catalogue"
occ "$PEER" app:enable openregister
occ "$PEER" app:enable opencatalogi

step "Enabling apps on the portal instance"
occ "$MAIN" app:enable openregister
occ "$MAIN" app:enable nldesign
occ "$MAIN" app:enable opencatalogi
# portaliq LAST: its install hook seeds a portal whose search page points at
# opencatalogi, so opencatalogi's own register has to exist by then.
occ "$MAIN" app:enable portaliq

# ---------------------------------------------------------------------------
# Seed the peer with publications.
#
# Written through OpenRegister's object API rather than SQL, so the rows go
# through the same validation, indexing and event path as any other write. A
# row inserted behind the API is not searchable, because nothing indexed it —
# and it looks identical in the database to one that is.
# ---------------------------------------------------------------------------
step "Seeding the peer catalogue"

peer_api() {
	curl -s -u admin:admin -H 'Content-Type: application/json' "$@"
}

PEER_REGISTER=$(occ "$PEER" config:app:get opencatalogi publication_register 2>/dev/null || true)
PEER_SCHEMA=$(occ "$PEER" config:app:get opencatalogi publication_schema 2>/dev/null || true)

if [ -z "${PEER_REGISTER}" ] || [ -z "${PEER_SCHEMA}" ]; then
	red "The peer has no publication register/schema configured."
	red "OpenCatalogi's install hook did not complete — check: docker logs $PEER"
	exit 1
fi

# `publicationDate` IS NOT OPTIONAL, and this is the single most important
# line in the script.
#
# The publication schema grants anonymous read conditionally:
#
#   "read": [{"group": "public", "match": {"publicationDate": {"$lte": "$now"}}},
#            "authenticated"]
#
# A row without a `publicationDate` therefore matches no public rule and is
# invisible to every anonymous caller — while remaining perfectly visible to
# the admin credentials this script seeds with. That asymmetry is what makes
# the mistake so easy: the seeder reads back its own rows successfully, the
# object count is right, and the portal shows nothing.
#
# Dated in the PAST for the same reason: `$lte $now` on a future date is a
# scheduled publication that has not happened yet.
SEED_DATE=${SEED_DATE:-2026-01-15T09:00:00+00:00}

# `organization` matches the value OpenCatalogi's own installer stamps on its
# example rows, so the seeded publications sit in the same catalog scope rather
# than in one of their own.
SEED_ORG=${SEED_ORG:-default-org}

seed_publication() {
	local title=$1 summary=$2 themes=$3
	local body existing_id

	# THEMES ARE SEEDED BECAUSE THE FACET COLUMN IS OTHERWISE UNTESTED.
	#
	# `themes` and not `categories`: the publication schema DECLARES `themes`,
	# and only a declared property can be faceted. A `categories` array is
	# stored, echoed back on the object, and faceted to nothing — the field is
	# invisible to search, so the column renders empty and reads as "no data".
	#
	# The portal facets on `themes`, and renders no facet column when the
	# API returns no buckets — correct behaviour, and indistinguishable on
	# screen from a facet feature that is broken. A rig whose rows carry no
	# categories therefore demonstrates search while proving nothing about
	# faceting, and the first person to notice would be a user with real data.
	body=$(python3 -c "
import json,sys
print(json.dumps({
  'title': sys.argv[1],
  'name': sys.argv[1],
  'summary': sys.argv[2],
  'description': sys.argv[2],
  'status': 'published',
  'publicationDate': sys.argv[3],
  'organization': sys.argv[4],
  'themes': [t for t in sys.argv[5].split(',') if t],
}))" "$title" "$summary" "$SEED_DATE" "$SEED_ORG" "$themes")

	# Matched by exact title against the admin-visible listing, then UPDATED
	# rather than skipped. A skip would leave a row seeded by an earlier,
	# incomplete run permanently invisible, and the script would report it as
	# "already present" every time.
	existing_id=$(peer_api "http://localhost:${PEER_PORT}/index.php/apps/openregister/api/objects/${PEER_REGISTER}/${PEER_SCHEMA}?_limit=100" |
		python3 -c '
import sys, json
want = sys.argv[1]
try:
    rows = json.load(sys.stdin).get("results", [])
except Exception:
    rows = []
for row in rows:
    if (row.get("title") or row.get("name")) == want:
        print((row.get("@self") or {}).get("id") or "")
        break
' "$title" 2>/dev/null || echo '')

	if [ -n "$existing_id" ]; then
		peer_api -X PUT -d "$body" \
			"http://localhost:${PEER_PORT}/index.php/apps/openregister/api/objects/${PEER_REGISTER}/${PEER_SCHEMA}/${existing_id}" \
			>/dev/null
		echo "  ~ $title (updated)"
		return
	fi

	peer_api -X POST -d "$body" \
		"http://localhost:${PEER_PORT}/index.php/apps/openregister/api/objects/${PEER_REGISTER}/${PEER_SCHEMA}" \
		>/dev/null
	echo "  + $title"
}

# Distinctive titles on purpose. A demo seeded with "Test 1".."Test 5" cannot
# show that a result came from the peer rather than from the local catalogue,
# because nothing about the row says where it lives.
# Themes OVERLAP on purpose. Five rows with five distinct themes
# produce five buckets of one, which looks like a facet column and cannot
# demonstrate that selecting one narrows anything.
seed_publication 'Vergunningen Rotterdam' \
	'Overzicht van verleende omgevingsvergunningen in de gemeente Rotterdam.' \
	'vergunningen,ruimte'
seed_publication 'Subsidieregister Rotterdam' \
	'Alle verstrekte subsidies met bedrag, ontvanger en doel.' \
	'financien,bestuur'
seed_publication 'Woo-verzoeken Rotterdam' \
	'Ingediende en afgehandelde Woo-verzoeken met besluit.' \
	'bestuur,openbaarheid'
seed_publication 'Parkeervergunningen Rotterdam' \
	'Uitgegeven parkeervergunningen per wijk.' \
	'vergunningen,verkeer'
seed_publication 'Bestemmingsplannen Rotterdam' \
	'Vastgestelde bestemmingsplannen en toelichtingen.' \
	'ruimte,openbaarheid'

# ---------------------------------------------------------------------------
# Seed the PORTAL instance with a couple of its own publications.
#
# FACETS ARE COMPUTED OVER LOCAL ROWS ONLY. Measured on this rig: the peer
# facets its own five publications into six theme buckets, and the portal
# instance — showing all nine results, seven of them federated — returns ZERO
# buckets, because its own two rows carry no themes.
#
# So federated RESULTS aggregate and federated FACETS do not. Without local
# rows carrying themes the portal renders no facet column at all, which is
# indistinguishable from faceting being broken. Two local publications make
# the column real, and docs/tutorials record the limitation rather than
# letting the rig imply the facets span both catalogues.
# ---------------------------------------------------------------------------
step "Seeding the portal instance's own catalogue"

MAIN_REGISTER=$(occ "$MAIN" config:app:get opencatalogi publication_register 2>/dev/null || true)
MAIN_SCHEMA=$(occ "$MAIN" config:app:get opencatalogi publication_schema 2>/dev/null || true)

if [ -n "${MAIN_REGISTER}" ] && [ -n "${MAIN_SCHEMA}" ]; then
	PEER_PORT_SAVED=$PEER_PORT
	PEER_REGISTER_SAVED=$PEER_REGISTER
	PEER_SCHEMA_SAVED=$PEER_SCHEMA

	# seed_publication addresses one catalogue through these three values, so
	# pointing them at the portal instance reuses it verbatim rather than
	# growing a second, subtly different copy.
	PEER_PORT=$MAIN_PORT
	PEER_REGISTER=$MAIN_REGISTER
	PEER_SCHEMA=$MAIN_SCHEMA

	seed_publication 'Begroting gemeente Rotterdam' \
		'Vastgestelde begroting met programma-indeling en toelichting.' \
		'financien,bestuur'
	seed_publication 'Openbare besluitenlijst B en W' \
		'Wekelijkse besluitenlijst van het college van burgemeester en wethouders.' \
		'bestuur,openbaarheid'

	PEER_PORT=$PEER_PORT_SAVED
	PEER_REGISTER=$PEER_REGISTER_SAVED
	PEER_SCHEMA=$PEER_SCHEMA_SAVED
else
	red "The portal instance has no publication register/schema — skipping its own catalogue."
fi

# ---------------------------------------------------------------------------
# Point the main instance at the peer and sync.
# ---------------------------------------------------------------------------
# ---------------------------------------------------------------------------
# Bind `localhost` to the seeded portal.
#
# THIS IS A DEPLOYMENT DECISION, WHICH IS WHY IT IS HERE AND NOT IN PORTALIQ'S
# INSTALL HOOK. PortalResolver serves a portal only on a domain marked
# `verified: true`, and has no fallback on purpose — a "default portal" is how
# a multi-tenant host serves one tenant's content under another tenant's
# domain. An install hook that marked a domain verified would be asserting
# control of a hostname on the operator's behalf, defeating the flag entirely.
#
# On a throwaway demo box bound to localhost, making that assertion is the
# operator's call, and this script IS the operator.
# ---------------------------------------------------------------------------
step "Binding localhost to the demo portal"

PORTAL_REGISTER=$(docker exec -u www-data "$MAIN" php occ config:app:get portaliq demo_portal_provisioned 2>/dev/null || true)

if [ -z "${PORTAL_REGISTER}" ]; then
	red "Portaliq did not provision a demo portal — its install hook found no OpenRegister."
	red "Try: docker exec -u www-data $MAIN php occ maintenance:repair"
	exit 1
fi

python3 - "$MAIN_PORT" "$PORTAL_REGISTER" <<'PY'
import json, subprocess, sys, urllib.request, base64

port, portal_id = sys.argv[1], sys.argv[2]
base = f"http://localhost:{port}/index.php/apps/openregister/api"
auth = base64.b64encode(b"admin:admin").decode()


def call(method, url, payload=None):
    data = json.dumps(payload).encode() if payload is not None else None
    request = urllib.request.Request(url, data=data, method=method)
    request.add_header("Authorization", f"Basic {auth}")
    request.add_header("Content-Type", "application/json")
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.loads(response.read() or "{}")


# The portaliq register/schema ids are not fixed across installs, so they are
# discovered rather than assumed. A hardcoded id works on the machine it was
# written on and silently addresses somebody else's schema everywhere else.
registers = call("GET", f"{base}/registers?_limit=100").get("results", [])
register = next((r for r in registers if r.get("slug") == "portaliq"), None)
if register is None:
    sys.exit("no portaliq register found")

schemas = call("GET", f"{base}/schemas?_limit=200").get("results", [])
schema = next((s for s in schemas if s.get("slug") == "portal"), None)
if schema is None:
    sys.exit("no portal schema found")

url = f"{base}/objects/{register['id']}/{schema['id']}/{portal_id}"
portal = call("GET", url)
body = {k: v for k, v in portal.items() if not k.startswith("@")}

body["domains"] = [
    {"hostname": "localhost", "verified": True, "verifiedAt": "2026-01-15T09:00:00+00:00"},
    {"hostname": "oc-demo-main", "verified": True, "verifiedAt": "2026-01-15T09:00:00+00:00"},
]

call("PUT", url, body)
print(f"  bound localhost + oc-demo-main to portal {portal.get('title')!r}")
PY

step "Federating the portal instance with the peer"

# Overriding the DEFAULT directory rather than adding a second one. The
# built-in default is https://directory.opencatalogi.nl/apps/opencatalogi/api/
# directory, which answers `{"results":[],"total":0}` — leaving it in place
# means every sync also makes a network call that returns nothing, so an
# offline run reports a failed directory and the demo looks broken.
occ "$MAIN" config:app:set opencatalogi default_directory_url \
	--value "${PEER_INTERNAL}/index.php/apps/opencatalogi/api/directory"

# The peer is on a Docker network, so it resolves to an RFC1918 address, and
# OpenCatalogi's SSRF guard refuses those by default — correctly, since the
# same code path on a hosted instance is a request forgery primitive.
#
# This rig is exactly the case the allowance exists for: throwaway containers
# on a private bridge with no route to anything that matters. It stays OFF
# everywhere else, including on the manual install road, where the directory
# is a real public host and no allowance is needed.
occ "$MAIN" config:app:set opencatalogi allow_internal_directories --value yes

occ "$MAIN" maintenance:repair --include-expensive >/dev/null 2>&1 || true

step "Syncing the directory"

# AUTHENTICATED, and that is not optional.
#
# `/api/directory` is `@PublicPage`, so it accepts an anonymous POST — and
# then fails to write anything, because creating a Listing object is a write
# that OpenRegister's RBAC refuses to `Anonymous`:
#
#   "User 'Anonymous' does not have permission to 'create' objects in schema
#    'Listing'"
#
# The endpoint still answers `{"message": "Directory synchronized
# successfully"}` in that case. The failure is reported only in the COUNTS.
#
# POSTed with the `directory` parameter the endpoint requires; without it the
# answer is a 400 that also says nothing useful unless the body is read.
SYNC_RESULT=$(curl -s -u admin:admin -X POST \
	-H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
	-d "{\"directory\":\"${PEER_INTERNAL}/index.php/apps/opencatalogi/api/directory\"}" \
	"http://localhost:${MAIN_PORT}/index.php/apps/opencatalogi/api/directory")

# THE COUNTS ARE THE OUTCOME; THE MESSAGE IS NOT.
# An earlier version of this script printed `message` and reported a green
# sync that had created zero listings and recorded one failure.
echo "$SYNC_RESULT" | python3 -c '
import json, sys

try:
    body = json.load(sys.stdin)
except Exception:
    print("  sync returned a non-JSON response")
    sys.exit(1)

if body.get("error"):
    print("  sync rejected: " + str(body.get("message")) + " - " + str(body.get("error")))
    sys.exit(1)

data = body.get("data") or {}
print("  listings created=%s updated=%s unchanged=%s skipped=%s failed=%s"
      % (data.get("listings_created", 0), data.get("listings_updated", 0),
         data.get("listings_unchanged", 0), data.get("listings_skipped", 0),
         data.get("listings_failed", 0)))

for error in (data.get("errors") or []):
    print("    ! " + str(error))

sys.exit(1 if data.get("listings_failed") else 0)
' || {
	red "Directory sync reported failed listings."
	exit 1
}

# THE COUNTS ARE STILL NOT THE OUTCOME — READ THE EFFECT.
#
# On a re-run the peer's listing is reported as `skipped`
# ("belongs to directory ... processed separately", which is the BFS
# de-duplication working as intended), so every count can legitimately be zero
# on a rig that is correctly federated. Asserting on the counts therefore
# fails a healthy rig, and asserting on `created > 0` would only ever pass
# once.
#
# What actually matters is whether a listing for the peer EXISTS. That is a
# question about state, so it is answered by reading state back.
LISTING_REGISTER=$(occ "$MAIN" config:app:get opencatalogi listing_register 2>/dev/null || true)
LISTING_SCHEMA=$(occ "$MAIN" config:app:get opencatalogi listing_schema 2>/dev/null || true)

PEER_LISTED=$(curl -s -u admin:admin \
	"http://localhost:${MAIN_PORT}/index.php/apps/openregister/api/objects/${LISTING_REGISTER}/${LISTING_SCHEMA}?_limit=100" |
	python3 -c '
import sys, json
try:
    rows = json.load(sys.stdin).get("results", [])
except Exception:
    rows = []
print(sum(1 for r in rows if "oc-demo-peer" in str(r.get("directory") or "")))
' 2>/dev/null || echo 0)

if [ "${PEER_LISTED:-0}" -gt 0 ]; then
	echo "  the peer is listed on the portal instance"
else
	red "  the peer is NOT listed — sync reported no failures but recorded nothing"
	exit 1
fi

# ---------------------------------------------------------------------------
# VERIFY. The point of this block is that the script cannot report success
# without evidence: every check below reads back a value it did not write in
# the same breath.
# ---------------------------------------------------------------------------
step "Verifying"

failures=0

check() {
	local what=$1 actual=$2
	if [ -n "$actual" ] && [ "$actual" != "0" ] && [ "$actual" != "null" ]; then
		green "  ok   $what ($actual)"
	else
		red "  FAIL $what (got '${actual:-empty}')"
		failures=$((failures + 1))
	fi
}

PORTAL_TOTAL=$(curl -s "http://localhost:${MAIN_PORT}/index.php/apps/portaliq/api/content/site" |
	python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("title") or "")' 2>/dev/null || echo '')
check "the portal resolves and has a title" "$PORTAL_TOTAL"

# A RESOLVING PORTAL IS NOT A RENDERING PAGE, and the difference is invisible
# from the check above.
#
# Checked at /zoeken rather than /: the landing page is a hero and a section,
# and search moved to its own route to match the reference.
#
# The portal resolved, had a title, served its own header and footer — and
# rendered "Pagina niet gevonden" in between, because the seeded page
# referenced its portal by UUID while CmsReader scopes content by SLUG. Every
# object involved existed and was published. Only opening the page showed it.
PAGE_WIDGET=$(curl -s "http://localhost:${MAIN_PORT}/index.php/apps/portaliq/api/content/page?route=/zoeken" |
	python3 -c '
import sys, json
try:
    body = json.load(sys.stdin)
except Exception:
    print("")
    sys.exit(0)
widgets = ((body.get("body") or {}).get("widgets") or [])
print(next((w.get("widgetKey") for w in widgets if w.get("widgetKey")), ""))
' 2>/dev/null || echo '')
check "the search page at /zoeken carries the search widget" "$PAGE_WIDGET"

HOME_WIDGET=$(curl -s "http://localhost:${MAIN_PORT}/index.php/apps/portaliq/api/content/page?route=/" |
	python3 -c '
import sys, json
try:
    body = json.load(sys.stdin)
except Exception:
    print("")
    sys.exit(0)
widgets = ((body.get("body") or {}).get("widgets") or [])
print(next((w.get("widgetKey") for w in widgets if w.get("widgetKey")), ""))
' 2>/dev/null || echo '')
check "the landing page at / carries the hero" "$HOME_WIDGET"

DETAIL_WIDGET=$(curl -s "http://localhost:${MAIN_PORT}/index.php/apps/portaliq/api/content/page?route=/publicatie" |
	python3 -c '
import sys, json
try:
    body = json.load(sys.stdin)
except Exception:
    print("")
    sys.exit(0)
widgets = ((body.get("body") or {}).get("widgets") or [])
print(next((w.get("widgetKey") for w in widgets if w.get("widgetKey")), ""))
' 2>/dev/null || echo '')
check "the detail page at /publicatie carries the detail block" "$DETAIL_WIDGET"

SEARCH_TOTAL=$(curl -s "http://localhost:${MAIN_PORT}/index.php/apps/opencatalogi/api/federation/publications?_limit=1" |
	python3 -c 'import sys,json; print(json.load(sys.stdin).get("total",0))' 2>/dev/null || echo 0)
check "the federated search endpoint returns publications" "$SEARCH_TOTAL"

# THE NARROWEST IDENTIFIER AVAILABLE, and it is not a count.
#
# `Subsidieregister Rotterdam` exists ONLY on the peer, and the assertion is
# on the DIRECTORY the matching row reports — not on the number of matches. A
# count is satisfied by local rows; a count of 1 is satisfied by a local row
# that happens to share a word. Reading back the peer's own hostname from the
# result is the only form of this check that federation is required to pass.
PEER_SOURCE=$(curl -s "http://localhost:${MAIN_PORT}/index.php/apps/opencatalogi/api/federation/publications?_limit=5&_search=Subsidieregister" |
	python3 -c '
import sys, json
try:
    rows = json.load(sys.stdin).get("results", [])
except Exception:
    rows = []
for row in rows:
    if (row.get("name") or row.get("title") or "").startswith("Subsidieregister"):
        print((row.get("@self") or {}).get("directory") or "")
        break
' 2>/dev/null || echo '')

facet_state() {
	curl -s "http://localhost:${MAIN_PORT}/index.php/apps/opencatalogi/api/federation/publications?_limit=1&_facets%5Bthemes%5D%5Btype%5D=terms$1" |
		python3 -c '
import sys, json
try:
    body = json.load(sys.stdin)
except Exception:
    print("0|")
    sys.exit(0)
facet = (body.get("facets") or {}).get("themes") or {}
buckets = (facet.get("data") or {}).get("buckets") or facet.get("buckets") or []
signature = ",".join(sorted(
    "%s=%s" % (b.get("value") or b.get("key"), b.get("count") or b.get("results"))
    for b in buckets
))
print("%d|%s" % (len(buckets), signature))
' 2>/dev/null || echo "0|"
}

LOCAL_FACETS=$(facet_state '&_aggregate=false')
FEDERATED_FACETS=$(facet_state '')

check "the theme facet returns buckets on the local path" "${LOCAL_FACETS%%|*}"

# COMPARED, NOT COUNTED — and that distinction is the whole finding.
#
# Counting buckets says facets "work" on the federated request: it returns
# three of them. Comparing the buckets to the LOCAL-only ones shows they are
# byte-identical while the result totals are not:
#
#   _aggregate=false   4 results   bestuur=2, openbaarheid=1, financien=1
#   (federated)       11 results   bestuur=2, openbaarheid=1, financien=1
#
# Seven federated publications carrying themes of their own — vergunningen,
# ruimte, verkeer — appear in NO bucket. So facets are computed over local
# rows only, and a visitor filtering by theme filters a corpus that is not the
# one they are searching.
#
# An earlier version of this script counted buckets and reported this as
# "facets survive aggregation". It also reported "facets are dropped" on a rig
# whose local rows happened to have no themes at all. Both readings came from
# asking how MANY rather than which.
if [ "${LOCAL_FACETS#*|}" = "${FEDERATED_FACETS#*|}" ] && [ "${LOCAL_FACETS%%|*}" != "0" ]; then
	printf '\033[33m  KNOWN  facet buckets are LOCAL-ONLY: identical to the _aggregate=false buckets\033[0m\n'
	printf '\033[33m         while the result count differs (%s federated vs %s local). Federated\033[0m\n' \
		"$SEARCH_TOTAL" "$(curl -s "http://localhost:${MAIN_PORT}/index.php/apps/opencatalogi/api/federation/publications?_limit=1&_aggregate=false" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("total",0))' 2>/dev/null || echo '?')"
	printf '\033[33m         rows are searchable but do not contribute to any bucket. See\033[0m\n'
	printf '\033[33m         docs/tutorials/admin/04-rotterdam-demo-portal.md.\033[0m\n'
elif [ "${FEDERATED_FACETS%%|*}" = "0" ]; then
	printf '\033[33m  KNOWN  no facet buckets on the federated request at all (%s results).\033[0m\n' "$SEARCH_TOTAL"
else
	green "  ok   federated facets differ from local ones — buckets now span the federation"
fi

if [ -n "$PEER_SOURCE" ] && [ "$PEER_SOURCE" != "local" ]; then
	green "  ok   a publication that exists ONLY on the peer is searchable, and names its source ($PEER_SOURCE)"
else
	red "  FAIL the peer's publication is not reaching the portal (source: '${PEER_SOURCE:-not found}')"
	failures=$((failures + 1))
fi

echo
if [ "$failures" -gt 0 ]; then
	red "$failures check(s) failed — the rig is up but not demonstrating federation."
	red "Most likely the directory sync has not run yet; re-run this script in a minute."
	exit 1
fi

green "Demo rig ready."
echo
echo "  Portal:          http://localhost:${MAIN_PORT}/index.php/apps/portaliq/site"
echo "  Peer catalogue:  http://localhost:${PEER_PORT}/index.php/apps/opencatalogi"
echo "  Admin login:     admin / admin"
