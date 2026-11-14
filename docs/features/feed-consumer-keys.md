# Feed consumer keys

A catalogue's protected feeds, such as its OOAPI 5.0 feed, are not public. An administrator can give an outside consumer, such as an education partner's application, its own key for one catalogue. The key reads that catalogue's protected feeds and nothing else.

## Issuing a key

1. Add the consumer to the catalogue: `POST /api/catalogs/{catalogSlug}/consumers` with `name` and, optionally, `contact`. OpenCatalogi creates a Nextcloud account `feed-...` for it, in the group `opencatalogi-feed-{catalogSlug}`, with a password nobody sees.
2. Generate a key: `POST /api/catalogs/{catalogSlug}/consumers/{id}/keys`, optionally with a `label` and an `expiresAt` date. The answer holds the key. It is shown this once: OpenCatalogi keeps only a one-way hash of it.

The consumer sends the key as `Authorization: Bearer <key>`:

```
GET /index.php/apps/opencatalogi/api/catalogs/hva-onderwijs/ooapi/v5/courses
Authorization: Bearer oc_...
```

The same key on another catalogue answers 403. A wrong, revoked or expired key answers 401.

## Rotating and revoking

A consumer can hold two active keys at once. To rotate, generate a second key, let the consumer switch to it, then revoke the old one: `POST /api/catalogs/{catalogSlug}/consumers/{id}/keys/{keyId}/revoke`. `GET /api/catalogs/{catalogSlug}/consumers` lists each key with when it was created, when it expires and when it was last used (written at most once an hour), so you can see the old key is no longer in use before you revoke it.

`POST /api/catalogs/{catalogSlug}/consumers/{id}/disable` disables the consumer and its account; its keys are refused from then on.

All of these endpoints are for administrators only.

## Accounts on the instance-wide list

Reading with a Nextcloud account and app password keeps working. Those accounts are governed by the instance-wide setting `ooapi_consumers` (a comma-separated list of account ids; empty means every account), which covers every catalogue. The consumer listing returns that list read-only under `instanceAccounts`.
