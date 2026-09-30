# WOO Transparency

## Overview

WOO (Wet open overheid) compliance features enable government organizations to manage the publication of documents under Dutch open government law. This includes document assessment, redaction workflow, batch processing, and inventarislijst (inventory list) generation.

## Document Queue

The WOO document queue provides a batch processing interface for documents received from case management systems (e.g., Procest). Documents flow through assessment statuses:

- **Ontvangen** (Received) - Document enters the queue
- **Beoordeling** (Assessment) - Document under review
- **Openbaar** (Public) - Document approved for publication
- **Geweigerd** (Refused) - Document refused with grounds

## Weigeringsgronden

When refusing publication, assessors select applicable legal grounds (weigeringsgronden) from the WOO article references. These are stored as structured metadata.

## Batch Processing

Related documents are grouped into WOO batches. Bulk assessment enables processing multiple documents with the same decision simultaneously.

## Inventarislijst

The inventarislijst is a structured export listing all documents in a batch with their assessment decisions, applicable weigeringsgronden, and publication status.

## Connecting to the Woo-index

The national Woo-index harvests your DiWoo sitemaps. It needs two things from you: a `robots.txt` at the root of your domain that points at them, and a registration.

### robots.txt at the domain root

OpenCatalogi serves the file at `/index.php/apps/opencatalogi/api/robots.txt`. It lists one `Sitemap:` line per category of every catalogue that publishes a Woo sitemap, and allows the API paths.

Nextcloud answers `/robots.txt` at the root itself, with `Disallow: /`. So the harvester never reaches the app's file until you add a web-server rule. The Woo-index section of the OpenCatalogi admin settings shows both rules for your own base URL, each with a copy button:

- **nginx:** a `location = /robots.txt` block that rewrites to the app's route.
- **Apache:** a `RewriteRule` for the Nextcloud `.htaccess` or the virtual host.

When the reading room runs on its own domain, add the rule on that domain. The readiness check reads the root file; when it finds no sitemap line there, it says so and points at these rules.

### Registration

Press **Request registration**. OpenCatalogi composes the request: the organisation's name and TOOI identifier, the root `robots.txt` URL and every sitemap index. It hands the request to integriq's `national-woo-index` channel. The status moves to requested only when the gateway answered, and the answer is kept.

With no gateway installed, nothing is recorded as sent. The panel shows the composed request, so you can send it another way. When the Woo-index confirms, press **Mark as registered**.

### A current verdict

The readiness check runs once a day while a catalogue publishes a Woo sitemap. It also runs once right after a catalogue is switched on for Woo.
