# DCAT linked data and the data.overheid.nl registration

The DCAT-AP-NL feed puts a catalogue on data.overheid.nl. This page covers two things around that feed: the dataset IRIs answer as linked data, and the admin settings show whether data.overheid.nl actually harvests the feed.

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
