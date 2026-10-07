# Tasks: portal-woo-request-intake

- [x] **T1**: `WooRequestIntake::receive()` mints, stores and arms, and reports `not-armed` when no due date came back
  - `WooRequestIntakeTest`
- [x] **T2**: `PortalContributionProvider::receiveWooRequest()` delegates to it, and answers `unavailable` without it
  - `WooRequestIntakeTest::testThePortalProviderHandsTheRequestToTheIntake`
