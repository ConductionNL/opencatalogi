# DCAT linked data and the data.overheid.nl registration

The DCAT-AP-NL feed puts a catalogue on data.overheid.nl. This page covers three things around that feed: the licences a catalogue or publication can carry, the dataset IRIs that answer as linked data, and the admin settings that show whether data.overheid.nl actually harvests the feed.

## The licence list

A licence in the feed is always an IRI from the EU licences authority list (`http://publications.europa.eu/resource/authority/licence`). OpenCatalogi bundles the members of that list that data.overheid.nl accepts, checked against its CKAN `license_list`. The bundled list is in `lib/Settings/dcat_waardelijsten.json` under `licences`, with its source and version.

| Code | Licence | data.overheid.nl label |
|---|---|---|
| `CC0` | Creative Commons CC0 1.0 Universal | CC-0 (1.0) |
| `CC_BY_4_0` | Creative Commons Attribution 4.0 International | CC-BY (4.0) |
| `CC_BYNC_4_0` | Creative Commons Attribution-NonCommercial 4.0 International | CC-BY-NC (4.0) |
| `CC_BYSA_4_0` | Creative Commons Attribution-ShareAlike 4.0 International | CC-BY-SA (4.0) |
| `CC_PDM_1_0` | Creative Commons Public Domain Mark 1.0 Universal | Publiek domein |

The three OWMS licences on the data.overheid.nl list (geslotenlicentie, geogedeeld, licentieonbekend) have no EU authority IRI, so they are not offered.

- **Where you pick one.** The catalogue form (once the DCAT feed is on), the publication form and the instance default in the admin DCAT settings each offer the list in a picker. Nothing outside the list can be typed. The stored value is the authority IRI. A publication without a licence takes its catalogue's licence, and a catalogue without one takes the instance default.
- **What is refused.** Saving a new or changed licence that is not on the list is refused with the name of the property. A stored value from before the list existed can stay as it is, so an unrelated edit is not blocked.
- **What the feed does with an unknown value.** A licence the list does not resolve is left out of the feed and reported once per dataset by the DCAT validator (`axis: dct:license`).
- **The list over the API.** `GET /apps/opencatalogi/api/dcat/licences` answers the list with its source and version, for any signed-in user.

## A dataset IRI answers as linked data

Every dataset in the feed has the IRI `/apps/opencatalogi/api/{catalogSlug}/{uuid}`, the public address of the publication.

- With `Accept: text/turtle` or `Accept: application/rdf+xml` the IRI answers the DCAT graph of that one dataset.
- With `Accept: text/html`, as a browser sends it, the IRI redirects to the publication page.
- Any other `Accept` header, and an explicit `?format=`, keeps the publication answer as before. `application/ld+json` stays the schema.org answer for search engines.

The graph comes from the same search as the catalogue feed, with the access rules on. A publication the caller cannot read, such as a draft, answers 404. So does every IRI of a catalogue whose DCAT feed is off.

## The data.overheid.nl registration

Registering with data.overheid.nl stays a step the operator takes by hand. The admin settings, next to the Woo-index registration, keep track of it.

- **Status.** The operator marks the registration as requested and as registered. Each gets the date it was first recorded.
- **Harvester visits.** A request for `/api/dcat` or `/api/catalogs/{slug}/dcat` whose user agent matches the configured pattern is recorded as a harvest. Without a pattern, nothing is recorded.
- **Dataset count.** A daily background job asks the data.overheid.nl CKAN API how many datasets it holds from our source, and counts the datasets our public feeds carry. The settings show both numbers with the date of the check.

The settings say harvested only on evidence: a recorded harvester visit or a count above zero. The operator's status alone never reads as harvested.

When the API cannot be reached, the last count and its date stay, and the settings show what went wrong.

### Settings

| Setting | What it holds |
|---|---|
| data.overheid.nl API address | The CKAN API root, ending in `/api/3`. It must be an https address. |
| Source catalogue on data.overheid.nl | The `source_catalog` value data.overheid.nl gives the datasets of this feed. |
| User agent of the data.overheid.nl harvester | A regular expression for the harvester's user agent. |

The count is CKAN's `package_search` action with `fq=source_catalog:"<source>"` and `rows=0`, read from `result.count`.

### Admin API

| Verb | Path | What it does |
|---|---|---|
| GET | `/apps/opencatalogi/api/dcat/donl-registration` | The status, the evidence and whether it reads harvested. |
| POST | `/apps/opencatalogi/api/dcat/donl-registration` | Records `status` (`not_requested`, `requested`, `registered`) and the settings. |

Both are for administrators only.
