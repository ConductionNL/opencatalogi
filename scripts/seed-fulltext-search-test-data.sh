#!/usr/bin/env bash
# ------------------------------------------------------------------
# seed-woo506-test-data.sh — seed a local Nextcloud/OpenCatalogi/OpenRegister
# install with a small WOO-inspired test set for full-text search work:
#
#   - 1 catalog (slug "publications", scoped to publication + document schemas)
#   - 2 publications with publicatiedatum in the past + a @self.slug
#   - 3 documents linked to those publications via
#     publication: { id, slug, title } (WOO-530 shape)
#   - 1 real 1-page PDF attached to every document, each carrying a
#     different recognizable search term in its body — so once
#     content-search lands (WOO-517) there's actually text to hit
#
# After the script finishes it self-verifies against both endpoints:
#
#   - GET /apps/opencatalogi/api/publications?_search=…
#   - GET /apps/opencatalogi/api/search?_search=…
#
# The verification step needs the WOO-530 fix
# (fix/pubquery-slug-metadata-lookup) merged into the OpenCatalogi
# version under test — otherwise /api/search returns publications only
# and the mixed-envelope check fails. The script prints a clear
# diagnostic in that case.
#
# Usage:
#   ./scripts/seed-woo506-test-data.sh
#     [--base-url http://localhost:9091]
#     [--user admin] [--pass admin]
#
# Env-var overrides are also supported: NC_BASE_URL, NC_USER, NC_PASS.
# ------------------------------------------------------------------

set -euo pipefail

# ---- config + args ----
NC_BASE_URL="${NC_BASE_URL:-http://localhost:9091}"
NC_USER="${NC_USER:-admin}"
NC_PASS="${NC_PASS:-admin}"

while [ "$#" -gt 0 ]; do
    case "$1" in
        --base-url) NC_BASE_URL="$2"; shift 2 ;;
        --user)     NC_USER="$2";     shift 2 ;;
        --pass)     NC_PASS="$2";     shift 2 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) echo "unknown arg: $1"; exit 2 ;;
    esac
done

OC_API="$NC_BASE_URL/apps/opencatalogi/api"
OR_API="$NC_BASE_URL/apps/openregister/api"
AUTH=(-u "$NC_USER:$NC_PASS" -H "OCS-APIRequest: true")

log()  { printf '\033[1;34m[seed]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[ ok ]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[fail]\033[0m %s\n' "$*"; exit 1; }

command -v curl   >/dev/null || fail "curl is required"
command -v python3 >/dev/null || fail "python3 is required (used to build a 1-page PDF)"

# ---- resolve register/schema config ----
log "Resolving register + schema config from OpenCatalogi app config…"
CONFIG_JSON=$(curl -sf "${AUTH[@]}" "$NC_BASE_URL/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/opencatalogi" -H "Accept: application/json" 2>/dev/null || true)

PUB_REGISTER=$(curl -sf "${AUTH[@]}" "$NC_BASE_URL/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/opencatalogi/publication_register?format=json" 2>/dev/null | python3 -c "import sys,json; print(json.load(sys.stdin)['ocs']['data']['data'])" 2>/dev/null || echo "")
PUB_SCHEMA=$(curl -sf "${AUTH[@]}"   "$NC_BASE_URL/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/opencatalogi/publication_schema?format=json" 2>/dev/null   | python3 -c "import sys,json; print(json.load(sys.stdin)['ocs']['data']['data'])" 2>/dev/null || echo "")
DOC_SCHEMA=$(curl -sf "${AUTH[@]}"   "$NC_BASE_URL/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/opencatalogi/document_schema?format=json" 2>/dev/null      | python3 -c "import sys,json; print(json.load(sys.stdin)['ocs']['data']['data'])" 2>/dev/null || echo "")
CAT_SCHEMA=$(curl -sf "${AUTH[@]}"   "$NC_BASE_URL/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/opencatalogi/catalog_schema?format=json" 2>/dev/null       | python3 -c "import sys,json; print(json.load(sys.stdin)['ocs']['data']['data'])" 2>/dev/null || echo "")

if [ -z "$PUB_REGISTER" ] || [ -z "$PUB_SCHEMA" ] || [ -z "$DOC_SCHEMA" ] || [ -z "$CAT_SCHEMA" ]; then
    fail "Missing app-config keys — is OpenCatalogi installed and setup-wizard run? (need publication_register, publication_schema, document_schema, catalog_schema)"
fi
ok "register=$PUB_REGISTER  publication_schema=$PUB_SCHEMA  document_schema=$DOC_SCHEMA  catalog_schema=$CAT_SCHEMA"

# ---- helper: JSON create ----
json_post() {
    local url="$1"; shift
    local body="$1"; shift
    curl -sf "${AUTH[@]}" -X POST "$url" -H "Content-Type: application/json" -d "$body"
}

# ---- 1. catalog ----
log "Ensuring catalog with slug 'publications'…"
EXISTING_CATALOG=$(curl -sf "${AUTH[@]}" "$OC_API/catalogi" 2>/dev/null | python3 -c "
import sys, json
d = json.load(sys.stdin)
for r in d.get('results', []):
    if (r.get('@self') or {}).get('slug') == 'publications':
        print(r['@self']['id']); break
" 2>/dev/null || true)

if [ -n "$EXISTING_CATALOG" ]; then
    ok "catalog already exists: $EXISTING_CATALOG"
    CATALOG_ID="$EXISTING_CATALOG"
else
    # Slug is stored on both the schema property AND the @self.slug metadata
    # column — the CatalogiService::getBySlug lookup queries the schema
    # property. `listed: true` + a past `published` timestamp are what makes
    # the catalog discoverable to anonymous callers.
    CATALOG_ID=$(json_post "$OR_API/objects/$PUB_REGISTER/$CAT_SCHEMA" "$(python3 -c "
import json
print(json.dumps({
    'slug':        'publications',
    'title':       'Publieke publicaties',
    'description': 'WOO-catalogus voor de openbare publicaties',
    'registers':   [int('$PUB_REGISTER')],
    'schemas':     [int('$PUB_SCHEMA'), int('$DOC_SCHEMA')],
    'listed':      True,
    'published':   '2026-01-01T00:00:00+00:00',
    '@self':       {'slug': 'publications', 'published': '2026-01-01T00:00:00+00:00'},
}))
")" | python3 -c "import sys,json; print(json.load(sys.stdin)['@self']['id'])")
    ok "catalog created: $CATALOG_ID"
fi

# ---- 2. publications ----
# Slug will be auto-generated by OR from @self.slug or from title if not set.

seed_pub() {
    local slug="$1" title="$2" summary="$3" description="$4"
    local body
    body=$(python3 -c "
import json
print(json.dumps({
    'title':             '$title',
    'summary':           '$summary',
    'description':       '$description',
    'publicatiedatum':   '2026-05-01T00:00:00+00:00',
    'status':            'published',
    '@self':             {'slug': '$slug'},
}))
")
    local resp
    resp=$(curl -s "${AUTH[@]}" -X POST "$OR_API/objects/$PUB_REGISTER/$PUB_SCHEMA" \
        -H "Content-Type: application/json" -d "$body")
    echo "$resp" | python3 -c "
import sys, json
try:
    d = json.loads(sys.stdin.read())
    if '@self' in d and 'id' in d['@self']:
        print(f\"{d['@self']['id']}\t{d['@self'].get('slug','')}\t{d.get('title','?')}\")
    else:
        print(f'ERROR: {json.dumps(d)}', file=sys.stderr)
        sys.exit(1)
except Exception as e:
    print(f'PARSE_ERR: {e}', file=sys.stderr)
    sys.exit(1)
"
}

log "Creating 2 publications…"
PUB1_LINE=$(seed_pub "woo-verzoek-2026-001-klimaatakkoord" "Woo-verzoek 2026-001 — Klimaatakkoord evaluatie" \
    "Verzoek tot openbaarmaking van interne evaluatie documenten rondom het Klimaatakkoord." \
    "Deze publicatie bevat de openbaar gemaakte documenten in reactie op Woo-verzoek 2026-001, gericht op interne evaluaties van het Klimaatakkoord.")
PUB2_LINE=$(seed_pub "convenant-duurzame-energie-2026" "Convenant Duurzame Energie 2026" \
    "Convenant tussen overheden en marktpartijen over duurzame-energie-doelen voor 2026." \
    "De tekst van het convenant plus bijbehorende toelichting.")
PUB1_ID=$(echo "$PUB1_LINE" | cut -f1); PUB1_SLUG=$(echo "$PUB1_LINE" | cut -f2); PUB1_TITLE=$(echo "$PUB1_LINE" | cut -f3)
PUB2_ID=$(echo "$PUB2_LINE" | cut -f1); PUB2_SLUG=$(echo "$PUB2_LINE" | cut -f2); PUB2_TITLE=$(echo "$PUB2_LINE" | cut -f3)
ok "pub1: $PUB1_ID slug=$PUB1_SLUG"
ok "pub2: $PUB2_ID slug=$PUB2_SLUG"

# ---- 3. documents ----

seed_doc() {
    local title="$1" filename="$2" summary="$3" pub_id="$4" pub_slug="$5" pub_title="$6"
    local body
    body=$(python3 -c "
import json
print(json.dumps({
    'title':           '$title',
    'filename':        '$filename',
    'mimeType':        'application/pdf',
    'summary':         '$summary',
    'publicatiedatum': '2026-05-01T00:00:00+00:00',
    'publication': {
        'id':    '$pub_id',
        'slug':  '$pub_slug',
        'title': '$pub_title',
    },
}))
")
    json_post "$OR_API/objects/$PUB_REGISTER/$DOC_SCHEMA" "$body" \
        | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['@self']['id'])"
}

log "Creating 3 documents…"
DOC1_ID=$(seed_doc "Notulen stuurgroep Klimaatakkoord — 12 april 2026" \
    "notulen-stuurgroep-klimaat-2026-04-12.pdf" \
    "Vergaderverslag met besluitpunten over de voortgang van de sectorafspraken." \
    "$PUB1_ID" "$PUB1_SLUG" "$PUB1_TITLE")
DOC2_ID=$(seed_doc "Interne evaluatie Klimaatakkoord Q1 2026" \
    "interne-evaluatie-klimaat-q1-2026.pdf" \
    "Kwartaalrapportage over de uitvoering van klimaat-akkoord afspraken." \
    "$PUB1_ID" "$PUB1_SLUG" "$PUB1_TITLE")
DOC3_ID=$(seed_doc "Convenanttekst Duurzame Energie 2026 — definitieve versie" \
    "convenanttekst-duurzame-energie-2026.pdf" \
    "De definitieve, ondertekende versie van het convenant." \
    "$PUB2_ID" "$PUB2_SLUG" "$PUB2_TITLE")
ok "doc1: $DOC1_ID / doc2: $DOC2_ID / doc3: $DOC3_ID"

# ---- 4. real 1-page PDFs ----
# We build a byte-valid 1-page PDF inline (~600 bytes) with a unique text
# per document so future content-search can actually hit different words
# per file. No external deps beyond stdlib python3.

make_pdf_b64() {
    local text="$1"
    python3 -c "
import base64
text = '''$text'''
body_stream = f'BT /F1 12 Tf 50 720 Td ({text}) Tj ET'
body = f'<< /Length {len(body_stream)} >>\nstream\n{body_stream}\nendstream'.encode('latin-1')
header = b'%PDF-1.4\n%\xe2\xe3\xcf\xd3\n'
objs = [
    b'1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n',
    b'2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n',
    b'3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>\nendobj\n',
    b'4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n',
    b'5 0 obj\n' + body + b'\nendobj\n',
]
out = bytearray(header)
offsets = []
for obj in objs:
    offsets.append(len(out))
    out += obj
xref_offset = len(out)
out += f'xref\n0 {len(objs)+1}\n0000000000 65535 f \n'.encode()
for off in offsets:
    out += f'{off:010d} 00000 n \n'.encode()
out += f'trailer\n<< /Size {len(objs)+1} /Root 1 0 R >>\nstartxref\n{xref_offset}\n%%EOF\n'.encode()
print(base64.b64encode(out).decode())
"
}

upload_pdf() {
    local doc_id="$1" filename="$2" text="$3"
    local b64
    b64=$(make_pdf_b64 "$text")
    # OpenRegister's CreateFileHandler auto-detects base64 in the `content`
    # string and decodes it before writing. Just pass the base64 through
    # untouched — no local decode step (that would double-encode as UTF-8
    # and corrupt the PDF's binary marker bytes).
    local body
    body=$(python3 -c "
import json
print(json.dumps({
    'name':    '$filename',
    'content': '$b64',
    'share':   False,
}))
")
    local resp
    resp=$(json_post "$OR_API/objects/$PUB_REGISTER/$DOC_SCHEMA/$doc_id/files" "$body")
    echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('id') or d.get('fileId') or d)" 2>/dev/null || echo "$resp"
}

log "Uploading 1-page PDF to each document…"
upload_pdf "$DOC1_ID" "notulen-stuurgroep-klimaat-2026-04-12.pdf" \
    "Notulen stuurgroep Klimaatakkoord 12 april 2026 sectorafspraken voortgang" >/dev/null
ok "doc1 → PDF uploaded"
upload_pdf "$DOC2_ID" "interne-evaluatie-klimaat-q1-2026.pdf" \
    "Interne evaluatie Klimaatakkoord Q1 2026 kwartaalrapportage uitvoering" >/dev/null
ok "doc2 → PDF uploaded"
upload_pdf "$DOC3_ID" "convenanttekst-duurzame-energie-2026.pdf" \
    "Convenanttekst Duurzame Energie 2026 definitieve versie duurzaamheid" >/dev/null
ok "doc3 → PDF uploaded"

# ---- 5. verify ----
log "Verifying — GET /publications ?_search=convenant …"
PUB_TOTAL=$(curl -s "$OC_API/publications?_search=convenant&_limit=10" -H "OCS-APIRequest: true" | python3 -c "
import sys, json
try: print(json.load(sys.stdin).get('total','?'))
except: print('parse-error')
")
if [ "$PUB_TOTAL" = "0" ]; then
    warn "endpoint 1 returned 0 hits — is the catalog scoped to the publication schema?"
else
    ok "endpoint 1 hits: $PUB_TOTAL"
fi

log "Verifying — GET /api/search ?_search=convenant  (mixed envelope)…"
SEARCH_RESULTS=$(curl -s "$OC_API/search?_search=convenant&_limit=10" -H "OCS-APIRequest: true")
DOC_ROWS=$(echo "$SEARCH_RESULTS" | python3 -c "
import sys, json
d = json.load(sys.stdin)
docs = [r for r in d.get('results',[]) if (r.get('@self') or {}).get('schema') == 'document']
pubs = [r for r in d.get('results',[]) if (r.get('@self') or {}).get('schema') == 'publication']
print(f\"total={d.get('total','?')} pubs={len(pubs)} docs={len(docs)}\")
")
ok "endpoint 2: $DOC_ROWS"
if echo "$DOC_ROWS" | grep -q "docs=0"; then
    warn "endpoint 2 returned 0 documents — the WOO-530 fix (fix/pubquery-slug-metadata-lookup) is required for documents to appear here. Once it lands, re-run the search to confirm mixed rows."
fi

log "Verifying — files attached to each document…"
for did in "$DOC1_ID" "$DOC2_ID" "$DOC3_ID"; do
    count=$(curl -s "${AUTH[@]}" "$OR_API/objects/$PUB_REGISTER/$DOC_SCHEMA/$did/files" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    if 'results' in d: print(len(d['results']))
    elif isinstance(d, list): print(len(d))
    else: print('?')
except: print('?')
")
    if [ "$count" = "0" ] || [ "$count" = "?" ]; then
        warn "doc $did — file count check inconclusive (got $count)"
    else
        ok "doc $did — $count file(s) attached"
    fi
done

log "Seed complete."
echo ""
echo "Try it:"
echo "  curl \"$OC_API/publications?_search=klimaat&_limit=5\" -H \"OCS-APIRequest: true\" | jq"
echo "  curl \"$OC_API/search?_search=convenant&_limit=5\" -H \"OCS-APIRequest: true\" | jq"
