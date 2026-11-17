# Woo requests are handled by dossiq

OpenCatalogi publishes. Receiving a Woo request and keeping its statutory
term is case work, and that now happens in dossiq.

## What changes

- **With dossiq installed, nothing changes for the requester.** A new request,
  from the portal form or from `POST /apps/opencatalogi/api/woo/requests`, goes
  to dossiq. Dossiq gives it its reference and its term, and the answer the
  requester sees is dossiq's.
- **The requests already stored in OpenCatalogi move to dossiq** with their
  remaining time, on upgrade. Their old reference still finds them. OpenCatalogi
  stops its own term reminders for every request that moved, so the reminders
  never run twice. A request dossiq could not move keeps its reminders here.
- **Without dossiq, OpenCatalogi no longer takes Woo requests.** The request
  routes answer 404, and the portal form answers that the intake is
  unavailable. Install dossiq to keep taking them. Publishing decisions and
  documents works as before.

## What the administrator sees

The Woo section of the admin settings has a **Woo requests** block:

- Without dossiq it says that Woo requests are handled by dossiq, which is not
  installed, with a link to dossiq in the app store.
- With dossiq it shows how many stored requests are not moved yet, how many of
  those dossiq could not move, and when that was counted.
- Once every request has moved, it says the requests stored here are read-only.

The same counts are in the Woo readiness report, under `wooRequests`. They do
not decide the readiness verdict: they say nothing about whether the Woo index
can harvest the publications.

## Read-only

When a hand-over run leaves no request unmoved, the stored requests become
read-only and stay so:

- A change through OpenCatalogi's routes (extend, pause, resume, attach a
  batch) answers 410, naming dossiq.
- A write through OpenRegister is refused before it is saved, except the
  `migratedTo` and `migratedAt` stamp that dossiq's import writes.

Reading a request and the terms report still work.

## Settings

| Key | What |
| --- | --- |
| `woo_requests_unmigrated` | Stored requests not moved to dossiq at the last count |
| `woo_requests_failed` | Of those, the ones dossiq could not move |
| `woo_requests_migration_checked_at` | When they were counted |
| `woo_requests_read_only` | `1` once no request was left to move |
