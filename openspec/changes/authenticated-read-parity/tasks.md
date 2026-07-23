# Tasks: authenticated-read-parity

- [ ] Freeze delta spec `specs/catalogs/spec.md` (ADDED CAT-AUTH-001); `openspec validate authenticated-read-parity` green
- [ ] Extract the `$unwantedProperties` strip list to a single private constant; audit `PublicationService`/`PublicationQueryService`/controllers for duplicate strip lists and route them through it
  - Spec ref: CAT-AUTH-001
  - Acceptance: grep shows exactly one definition of the strip list in lib/
- [ ] Inject `IUserSession` into `CatalogiService` (+ confirmed siblings); strip only when `getUser() === null`
  - Spec ref: CAT-AUTH-001
  - Acceptance: PHPUnit: authenticated envelope carries owner/locked/retention; anonymous envelope byte-identical to golden fixture
- [ ] Object-set parity test: same ids/order for both audiences under identical mocked RBAC context
  - Spec ref: CAT-AUTH-001 scenario 3
- [ ] Remove the `@todo` comment at the strip site; `@spec` tags on changed methods; hydra gates green locally (spec-coverage, security-change-has-tests — tests/ touched in same PR)
