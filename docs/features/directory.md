# Directory: listings and federation

OpenCatalogi instances form a federated network. Each instance keeps a **directory**: a
list of **listings**, one per catalogue it knows about, on this instance or on another
one. A listing says where that catalogue can be searched and where its publications can
be read. The Directory page shows them; the search can include the catalogues whose
listing allows it.

## Listings

A listing is an OpenRegister object. The register and schema it lives in are the app
settings `listing_register` and `listing_schema`; every listing operation is scoped to
that pair, and refuses to run when it is not configured.

| Field | Meaning |
|---|---|
| `catalogusId` | The id of the catalogue on its own instance |
| `title`, `summary`, `description` | What the Directory page shows |
| `search` | The catalogue's search endpoint |
| `publications` | The catalogue's publications endpoint |
| `directory` | The directory endpoint of the instance that holds it |
| `status` | `development`, `beta`, `stable` or `obsolete` |
| `statusCode`, `statusMessage` | The outcome of the last check |
| `lastSync` | When the listing was last synchronised |
| `integrationLevel` | `none`, `connection` or `search` |
| `default` | Whether this is the default listing |
| `schemas`, `organization`, `version` | The publication types, the owner, the OpenCatalogi version |

### Endpoints

| Method and URL under `/apps/opencatalogi` | Who | Does |
|---|---|---|
| `GET /api/listings` | signed in | Lists the listings, paginated |
| `GET /api/listings/{id}` | anyone | One listing |
| `POST /api/listings` | administrator | Creates a listing |
| `PUT /api/listings/{id}` | administrator | Updates a listing |
| `DELETE /api/listings/{id}` | administrator | Deletes a listing |
| `POST /api/listings/sync` | administrator | Synchronises one listing, or all of them |
| `POST /api/listings/add` | administrator | Adds the catalogues of another instance's directory |
| `GET /api/directory` | anyone | This instance's directory, for other instances |
| `POST /api/directory` | anyone, rate limited | Asks this instance to read a directory URL |

Each public endpoint also answers the browser's `OPTIONS` preflight, so another
instance's Directory page can call it. "Administrator" includes the people Nextcloud's
admin delegation allows into the OpenCatalogi settings.

Creating, changing and adding listings used to be open to any caller. They are limited to
administrators because a listing decides which servers this instance contacts.

## Synchronisation

`DirectoryService` reads a remote directory and turns each catalogue in it into a local
listing: title, summary and schemas are copied, and the `publications` URL is taken from
the directory data or, when it is missing, derived from the listing's search or directory address.

Two rules keep the network from looping or flapping:

- **No loops.** The service remembers which directory URLs it has processed for five
  minutes and does not read them again within that time, so two instances that list each
  other do not keep triggering each other.
- **No stale overwrites.** An incoming listing does not replace a stored one that was
  updated more recently. `lastSync` records the last attempt and `lastSuccessAt` the last
  success.

## Scheduled jobs

Both jobs are declared in `appinfo/info.xml`; Nextcloud runs only jobs declared there.

| Job | When | Does |
|---|---|---|
| `DirectorySync` | Every hour by default; the administrator can set 15 minutes to 24 hours | Synchronises every known directory |
| `Broadcast` | Every 4 hours | Tells the other instances in the network that this one exists, so they add it to their directory |

`Broadcast` existed for a long time without being declared in `info.xml`, so it never
ran. It is declared now.
