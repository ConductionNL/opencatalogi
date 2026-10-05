## ADDED Requirements

### Requirement: A Woo request delivered by the portal arms its term (REQ-WRI-008)

A Woo request portaliq delivers MUST go through the same intake as the API: opencatalogi mints the reference and arms the statutory term. The answer MUST say whether the term runs, and MUST NOT carry a due date when it does not.

#### Scenario: the portal delivers a request and the term is armed
- GIVEN a citizen sent a Woo request through a portal form bound to `wooRequest`
- WHEN portaliq calls `receiveWooRequest()` with the answers and the moment they were sent
- THEN a request is stored with a minted `WOO-` reference, received at that moment
- AND its term is armed and stored on it
- AND the answer is `armed` with the due date
- @e2e exclude backend call between two apps from a background job; pinned by `WooRequestIntakeTest`

#### Scenario: the term cannot be armed
- GIVEN the term engine is unavailable or refuses
- WHEN portaliq delivers a request
- THEN the request is stored, and the answer is `not-armed` with no due date and the reason
- @e2e exclude backend call between two apps from a background job; pinned by `WooRequestIntakeTest`
