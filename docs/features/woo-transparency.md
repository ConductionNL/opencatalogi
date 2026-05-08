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
