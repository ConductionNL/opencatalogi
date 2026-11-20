# Proposal: woo-compliance

## Summary

Implement and harden WOO (Wet Open Overheid) compliance features in OpenCatalogi: XML sitemap generation per catalog and WOO information category (informatiecategorie), DIWOO-conformant metadata in sitemap documents, robots.txt discovery, and a bug fix ensuring only catalogs with `hasWooSitemap: true` appear in robots.txt output.

## Motivation

Dutch government organizations are legally required to make their WOO publications discoverable by KOOP/DIWOO — the central national index operated under the Wet Open Overheid. OpenCatalogi already generates sitemaps and a robots.txt, but:

1. **robots.txt bug** (WOO-008): `RobotsController` includes every catalog that has a slug in its robots.txt output. It never checks `hasWooSitemap`. A catalog administrator who deliberately left `hasWooSitemap: false` still gets sitemap lines published, potentially exposing a catalog to DIWOO indexing before it is ready.
2. **DIWOO metadata completeness**: the `SitemapService` maps publication and file metadata to the DIWOO Document XML schema. This mapping is functionally implemented but has no formal specification or test coverage documenting the exact field sources and fallback rules.
3. **Pagination and validation**: sitemap index pagination (max 1000 entries per page) and category-code validation are implemented but undocumented and untested.

This change formalises the specification for all three sitemap/robots endpoints, fixes the robots.txt gate, and establishes a test baseline so future changes cannot silently regress WOO compliance behaviour.

## Scope

### In scope
- Fix `RobotsController` to filter catalogs by `hasWooSitemap: true` before emitting sitemap lines (WOO-008)
- Formal specification of all 3 public endpoints: sitemap index, DIWOO sitemap (publications), robots.txt
- Specification of the full DIWOO Document metadata mapping (11 fields, sources, and fallback rules)
- Specification of the 17 WOO information categories (informatiecategorieen) and their validation
- Specification of sitemap pagination (max 1000 publications per page)
- Specification of error responses for invalid category codes and unconfigured schemas
- Unit tests covering the robots.txt gate fix and DIWOO metadata mapping

### Out of scope
- Changes to SitemapService beyond the robots.txt gate fix — the service is otherwise correctly implemented
- Auto-publishing share link creation (covered by the `auto-publishing` spec)
- File management and download service (covered by their respective specs)
- WOO transparency workflow (reading room, inventarislijst, weigeringsgronden — covered by `woo-transparency` spec)
- PLOOI / national platform integration

## Risks

- **robots.txt regression**: narrowing the robots.txt output from "all slugged catalogs" to "all `hasWooSitemap: true` catalogs" is a breaking change for any catalog owner who relied on the implicit inclusion. Mitigation: the fix aligns behaviour with the documented intent of the `hasWooSitemap` field; any catalog that was being incorrectly included was already misconfigured.
- **DIWOO schema drift**: KOOP updates the DIWOO XSD schema periodically. The DIWOO namespace and field names specified here reflect the currently deployed schema; future schema revisions will require a follow-up change.
- **Empty `loc` fields**: when a publication's file has no `downloadUrl` (no share link created), the DIWOO `loc` element is empty. KOOP crawlers may reject empty `loc` values. Mitigation: this is an auto-publishing dependency documented in the cross-references; this spec does not attempt to fix it.
