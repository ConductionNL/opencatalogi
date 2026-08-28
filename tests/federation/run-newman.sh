#!/usr/bin/env bash
#
# OpenCatalogi Federation integration-test runner (Newman / Postman).
#
# Runs tests/federation/federation-tests.postman_collection.json — the
# OpenCatalogi cross-instance FEDERATION suite. Unlike the single-instance
# Newman suites in the rest of the fleet, this collection requires TWO live,
# federation-peered Nextcloud instances (nc1 + nc2) plus outbound DNS between
# them: it exercises DirectoryService::syncDirectory / BroadcastService::broadcast
# and the directory/search/federation-publications endpoints across instances.
#
# This is the Newman analogue of the PHPUnit `@group network` tests (excluded
# by default in phpunit.xml / phpunit-unit.xml for the same reason). It is NOT
# wired as a hard gate in app-tests-live.yml (run-newman stays false there)
# because the byte-identical single-NC live rig has neither a second instance
# nor outbound federation DNS. Run it MANUALLY against a real federation
# testbed (e.g. the ~/oc-federation-test fed1/fed2 rig) by passing the two URLs:
#
#   NC1_URL=http://localhost:8081 NC2_URL=http://localhost:8082 ./run-newman.sh
#
# Defaults target the docker-compose federation aliases nc-fed-1 / nc-fed-2.
#
# Uses a globally-installed `newman` if present, otherwise falls back to
# `npx newman`. Runs are serialised via flock (when available) so concurrent
# CI agents do not trip the Nextcloud brute-force protection.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

# Re-exec under an exclusive flock so parallel agents serialise.
LOCK_FILE="/tmp/uiaudit-opencatalogi.lock"
if [ "${OPENCATALOGI_NEWMAN_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
  export OPENCATALOGI_NEWMAN_LOCKED=1
  exec flock "${LOCK_FILE}" "$0" "$@"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION="${SCRIPT_DIR}/federation-tests.postman_collection.json"

NC1_URL="${NC1_URL:-http://nc-fed-1}"
NC2_URL="${NC2_URL:-http://nc-fed-2}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"

if command -v newman >/dev/null 2>&1; then
  NEWMAN=(newman)
else
  NEWMAN=(npx --yes newman)
fi

"${NEWMAN[@]}" run "${COLLECTION}" \
  --env-var "nc1Url=${NC1_URL}" \
  --env-var "nc2Url=${NC2_URL}" \
  --env-var "nc2Internal=${NC2_URL}" \
  --env-var "adminUser=${ADMIN_USER}" \
  --env-var "adminPass=${ADMIN_PASS}" \
  --reporters cli \
  --color on \
  "$@"
