# Tasks: remove-decided-no-dead-code

- [x] 1.1 Prove per symbol that nothing calls it (`git grep` over `lib src appinfo tests`), listed in the PR.
- [x] 1.2 Remove the routes, controller methods, services, registrations and the unmounted view. Verify: PHPUnit, psalm, phpstan, phpmd, route-reachability gate.
- [x] 1.3 Remove the unit and e2e tests of the removed code, the `openapi.json` paths, the now-unused strings in every locale, and the docs sections.
- [x] 1.4 Take the requirements out of the open changes and remove "Redaction with WOO context" from `woo-transparency`.
