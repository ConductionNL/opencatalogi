# Run a local demo

This page gets a working OpenCatalogi running on your own machine in two commands. You end with a themed public portal you can click through, backed by a real catalogue.

It is a **demo**, not a development environment. Nothing is mounted from a checkout, and that is deliberate — see [What this is not](#what-this-is-not).

## What you need

Docker, with Compose v2.23 or newer. Nothing else — no PHP, no Node, no Nextcloud.

```bash
docker --version
docker compose version
```

If `docker compose version` prints v2.22 or older, upgrade first. The compose file declares its scripts inline via `configs`, and older versions ignore the `content:` field silently, which produces an instance with no apps installed and no error.

## Step 1 — get the compose file

Download `opencatalogi-compose.yaml` from the repository root:

```bash
curl -fsSLO https://raw.githubusercontent.com/ConductionNL/opencatalogi/main/opencatalogi-compose.yaml
```

It is a single self-contained file. There is nothing else to fetch.

## Step 2 — start it

```bash
docker compose -f opencatalogi-compose.yaml up -d
```

The first run takes a few minutes: it pulls three images and downloads roughly 200 MB of application archives. Watch it work if you like:

```bash
docker compose -f opencatalogi-compose.yaml logs -f app-installer
```

You are looking for:

```
==> installing openregister 1.1.5
==> installing opencatalogi 1.0.9
==> installing portaliq 0.1.4
==> apps present: opencatalogi openregister portaliq
```

Then Nextcloud installs itself and enables the three apps in dependency order. That is done when this returns `"installed":true`:

```bash
curl -s http://localhost:8600/status.php
```

## Step 3 — open the demo

| What | Where |
| --- | --- |
| **Public portal** | <http://localhost:8600/apps/portaliq/site?portal=demo> |
| Admin interface | <http://localhost:8600> — `admin` / `admin` |
| Directory API | <http://localhost:8600/apps/opencatalogi/api/directory> |

The portal is the thing to look at. It is public: no login, and signing in as admin would show you a different (and more permissive) view than a visitor gets.

### Why the portal needs `?portal=demo`

An instance can host several portals, and Portaliq resolves which one to serve in exactly two ways: an explicit `?portal=<slug>` parameter, or a request hostname matching a portal's **verified** domain.

There is deliberately no third mode and no "default portal" fallback, because a default is how a multi-tenant host ends up serving one tenant's content under another tenant's domain. The seeded demo portal ships with no domains — an install hook has no business claiming a hostname on your behalf — so the slug parameter is how you reach it.

To drop the parameter on a throwaway box, bind `localhost` to the portal yourself under **Portaliq → Portals → demo → Domains** and mark it verified.

## Verifying it actually worked

A page loading is not the same as a page working. Nextcloud serves its shell before the app decides whether it has anything to render, so `/apps/portaliq/site?portal=demo` returns HTTP 200 even when the portal resolves to nothing. Check content, not status:

```bash
# Should name the portal, not answer {"error":"not_found"}
curl -s "http://localhost:8600/apps/portaliq/api/content/site?portal=demo"

# Should list at least one catalog, not {"results":[],"total":0}
curl -s "http://localhost:8600/apps/opencatalogi/api/directory"
```

An empty directory on a fresh instance does not mean "no federation peers" — it means the register configuration was never imported. Those two states look identical from the outside, which is why this check is worth running.

## Changing the defaults

Every version and the port are overridable on the command line:

```bash
DEMO_PORT=9000 \
OPENCATALOGI_VERSION=1.0.10 \
docker compose -f opencatalogi-compose.yaml up -d
```

Leaving a version empty resolves the newest release for that app, including pre-releases.

## Tearing it down

```bash
# Stop, keep the data
docker compose -f opencatalogi-compose.yaml down

# Stop and delete everything, including the database
docker compose -f opencatalogi-compose.yaml down -v
```

## What this is not

**It is not a development environment, and it cannot be turned into one by adding a bind mount.**

Nextcloud installs and updates an app by deleting the app directory and extracting a fresh archive over it. Point that at a checkout and an app-store update will delete your working tree — measured on a development machine on 27 August 2026, where `\OC\Updater::upgradeAppStoreApp` fired on a container restart and removed every top-level file from a bind-mounted checkout, including its `.git` directory. Only the subdirectories it lacked permission to unlink survived.

So this compose keeps its apps in a named volume and installs them from release archives. That also happens to be the only thing that works: a release archive is a **complete** app carrying `vendor/` and the built `js/` bundle, while a `git clone` carries neither — and a Nextcloud app with no `vendor/` does not fail loudly. It warns once and keeps loading, so the app appears installed while every service that needs a dependency is quietly absent.

To work *on* these apps rather than *with* them, use the development environment instead.

## Troubleshooting

**`app-installer` exits non-zero.** It could not download an archive. Check the log for the URL it tried; the most common cause is a pinned version that has no release.

**The portal renders unthemed.** NLDesign is not installed. That is expected — it is optional, and the theme resolver deliberately renders unthemed rather than wrong when it is absent.

**`/api/directory` answers `{"results":[],"total":0}`.** The register configuration was not imported. Re-run the import from **Settings → OpenCatalogi → Reload configuration**.

**Everything returns 404 after an upgrade.** Check `docker compose -f opencatalogi-compose.yaml logs nextcloud` for `requires upgrade`. Run `docker compose -f opencatalogi-compose.yaml exec -u www-data nextcloud php occ upgrade`.
