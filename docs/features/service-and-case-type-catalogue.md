# The published service and case type catalogue

Two catalogues that point opposite ways. Outward, a public list of everything
a resident or a company can request. Inward, case types taken from a published
external catalogue instead of typed into a blank form, and kept in step with
it.

## The public request catalogue

`GET /apps/opencatalogi/api/service-catalogue` is readable without an account
and answers the entries grouped, with their cost and their duration, plus a
count of the entries that cannot currently be started.

Every entry carries a form binding: the case type, the audience and the form
name. The catalogue holds no field list and the portal holds no catalogue. A
portal resolves the binding and renders the form; that split is the reason the
same entry can be shown on a municipal portal and read by an integrator
through the API.

### An entry whose form does not resolve

An entry is listed either way, with `available: false` and a reason:

| reason | what happened |
|---|---|
| `no-form-binding` | the entry names no form at all |
| `incomplete-form-binding` | one of the case type, audience or form name is missing |
| `unknown-case-type` | the entry names a case type this instance does not have |
| `unpublished-case-type` | the case type exists but is not published |

Hiding such an entry would make an obligation to publish look like an
obligation that does not exist, so nothing is hidden.
`GET /api/service-catalogue/unavailable`, admin only, is the repair list.

### An unreadable catalogue is not an empty one

When OpenRegister cannot be reached, or the registers were never configured,
the catalogue answers 503 with `catalogue-unreadable`. It never answers 200
with an empty list, because a resident reading an empty list concludes their
municipality offers nothing.

## Case types

A published case type links to the form that starts it and to the OpenAPI
description of the interface behind it. `GET /api/case-types/{id}` returns
both under `links`, with `syncedAt` beside them.

### Importing from an external catalogue

`POST /api/case-types/import` takes a registered source and an external
identifier. The transport is integriq's, not this app's: OpenCatalogi composes
the ask, hands it to the gateway and reads the answer.

The import records the source, the version, the moment, and a snapshot of what
arrived. The snapshot is what a later resynchronisation compares against, so a
property an administrator edited afterwards can be told apart from one that
never moved.

`GET /api/case-types/{id}/resync` shows the difference against the source and
flags every property that was changed locally. Nothing is applied by that
call. `POST` to the same URL with `accepted` naming the properties applies
exactly those and leaves the rest, including the properties that are local and
never came from the source at all.

When the source cannot be reached, both calls answer 502 with
`external-catalogue-unreachable`. An administrator told "no definitions" goes
to look at the national catalogue; one told "unreachable" goes to look at the
gateway.

## Knowledge articles

An article is a published record in a catalogue, so it is searchable and
public by the rules that already govern publications.

A reader records whether it helped, once. The verdict is stored as a salted
hash of the reader's token, so the counts can be published without any reader
being identifiable from what is stored. The counts are on the article; the
verdicts are not.

`POST /api/knowledge-articles/extract` turns an answer on a case into a draft
article that links back to the case. The draft is not public and the case is
not written to: the extraction copies, it never moves. An answer written to
one applicant is rarely the wording that belongs in public, so a person reads
the draft and decides.

## Which standards this implements

- The catalogue is the producten- en dienstencatalogue a gemeente publishes.
  This implements the catalogue and its API. It does not implement the UPL
  product coding, the Samenwerkende Catalogi exchange format, or any national
  registration of the catalogue itself.
- The case type import reads the shape a zaaktypecatalogus offers. It
  implements the import, the provenance and the resynchronisation diff. It
  does not implement the ZGW Catalogi API in full, and it holds no transport:
  integriq's gateway owns the connection.
