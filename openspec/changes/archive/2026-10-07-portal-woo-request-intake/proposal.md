# Proposal: portal-woo-request-intake

## Why

A citizen who sends a Woo request through a portaliq form never got a statutory term. portaliq delivered every intake with a plain OpenRegister create, so `WooRequestService` never minted a reference and `StatutoryTerm` never armed a term. The citizen still got portaliq's own reference, so it read as success.

portaliq delivers from a background job. There is no signed-in user there, so `POST /api/woo/requests` is out of reach.

## What changes

- `WooRequestIntake` runs the intake steps in process: mint, store, arm, store the term. It answers with an outcome (`armed`, `not-armed`, `refused`, `unavailable`) and never throws.
- `PortalContributionProvider::receiveWooRequest()` hands a portal request to it. portaliq already locates this provider by convention, so no new integration pattern is added.
- The term counts from when the citizen sent the request, which portaliq passes along.

## Not in this change

- The `receive()` endpoint keeps its own copy of the same steps. Moving it onto `WooRequestIntake` is a follow-up; it would change the controller's constructor and its tests.
