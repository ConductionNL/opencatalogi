# Tell the source system when its publication is complete

A source system, such as a case system, creates a publication in OpenCatalogi
through OpenRegister's object API. It wants to know when the publication is
complete and public, so it can tell the requester. OpenCatalogi sends no message
of its own for this. It records the moment as an ordinary OpenRegister update,
and the source system subscribes to that update with an OpenRegister webhook.

## What the source system gets back

The answer to the create call carries two read-only properties:

- `publicUrl` is the page where the public reads the publication. It is empty
  when no public page can be built.
- `officerUrl` is the page where an officer works on it in OpenCatalogi.

Every publication also carries `completeness`, which is `incomplete` or
`complete`. It is `complete` only when the publication is public, every attached
file has a public share, and there are at least `expectedDocuments` files when
the source system set that number. `completeAt` records when it became complete.
A caller cannot set any of these properties.

## The subscription

Create the webhook as the user the source system writes with, on
`POST /index.php/apps/openregister/api/webhooks`:

```json
{
  "name": "Publication complete",
  "url": "https://zaaksysteem.example.nl/hooks/publication-complete",
  "events": ["OCA\\OpenRegister\\Event\\ObjectUpdatedEvent"],
  "filters": {
    "oldObject.completeness": "incomplete",
    "newObject.completeness": "complete",
    "newObject.@self.owner": "zaaksysteem"
  }
}
```

Replace `zaaksysteem` with the user id of the source system. The two
`completeness` filters match only the update that moves the publication to
complete. A later edit of a complete publication goes from `complete` to
`complete`, so it is not sent.

To narrow the subscription to the publication register and schema, add their ids
to `filters`:

```json
{
  "register": "3",
  "schema": "12"
}
```

Each delivery carries the update payload. Its `newObject` holds the publication
with `publicUrl`, `officerUrl` and `completeAt`.

## Requirements

- OpenRegister with `newObject` and `oldObject` in the update payload
  (openregister#10572). Without them the filters match nothing.
- The publication register and schema set in the OpenCatalogi settings.
