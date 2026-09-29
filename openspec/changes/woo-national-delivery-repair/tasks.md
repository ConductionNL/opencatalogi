# Tasks: woo-national-delivery-repair

## 1. Repair the hand-over

- [ ] 1.1 Add the `channel_sources` setting and its editor in the Woo settings section (REQ-WND-001). Verify: `tests/Unit/Service/SettingsServiceTest.php` and `tests/e2e/woo-delivery.spec.ts`.
- [ ] 1.2 Make `handOver()` resolve the source object and call the real signature (REQ-WND-001). Verify: `tests/Unit/Service/Publication/NationalIndexServiceTest.php` with a double that extends the real `CallService` signature via `onlyMethods`, plus one case with no source set.
- [ ] 1.3 Send official notices as `GatewayDeliveryRequestedEvent` for the `publicatie` gateway and handle a null delivery and a refusal (REQ-WND-002). Verify: same test class, constructing the real event.

## 2. PLOOI

- [ ] 2.1 Add the optional `plooiStatus`, `plooiDeliveredAt`, `plooiIdentifier` properties and the catalogue flag `plooiDelivery` (REQ-WND-003). Verify: real payload validated against the real fragment.
- [ ] 2.2 Add the listener that delivers when a publication turns public (REQ-WND-003). Verify: `tests/Unit/Listener/PloOiDeliveryListenerTest.php` built on a real `ObjectUpdatedEvent` with an old and a new object.
- [ ] 2.3 Show the delivery status on the publication page (REQ-WND-003). Verify: e2e spec from 1.1.

## 3. Announce

- [ ] 3.1 Add the Announce action on the publication page calling `POST /api/publications/announce` (REQ-WND-004). Verify: e2e spec from 1.1.

## 4. Docs and strings

- [ ] 4.1 English and Dutch strings, docs, `openspec validate woo-national-delivery-repair --strict`.
- [ ] 4.2 Live check after merge: with an integriq source pointed at a local echo endpoint, publish one publication and read `plooiStatus` back.
