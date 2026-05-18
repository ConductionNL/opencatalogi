# Tasks: deelnames-gebruik

## Task 1: Implementation planning
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: done
- **Acceptance criteria**: Requirements from spec are decomposed into implementable tasks

## Task 2: ViewService — two-phase gebruik retrieval
- **Spec ref**: specs/deelnames-gebruik/spec.md#requirement-viewservice-must-retrieve-deelnames-gebruik-separately
- **Files**: lib/Service/ViewService.php
- [ ] Create ViewService with getGebruikForOrganization() performing two-phase retrieval
- [ ] Phase 1: regular gebruik query with standard RBAC
- [ ] Phase 2: deelnames query with _rbac: false and _multitenancy: false, filtering on deelnemers
- [ ] Deduplicate results — owned beats deelnames for the same module+referentiecomponent pair
- [ ] Add _type: "deelnames" marker on deelnames nodes
- [ ] Add _sourceOrganization and _sourceOrganizationId metadata to deelnames nodes
- [ ] Guard each phase independently so a failure in one does not prevent the other from returning

## Task 3: ViewEnrichmentController — REST API endpoint
- **Spec ref**: specs/deelnames-gebruik/spec.md#requirement-deelnames-gebruik-must-be-filterable-in-the-frontend
- **Files**: lib/Controller/ViewEnrichmentController.php, appinfo/routes.php
- [ ] Create ViewEnrichmentController with index() GET endpoint at /api/view-enrichment
- [ ] Accept query parameters: organization_id, include_gebruik, include_deelnames_gebruik
- [ ] Delegate to ViewService and return combined JSON response
- [ ] Register route in appinfo/routes.php (PublicPage + NoCSRFRequired)
- [ ] Add CORS preflight route

## Task 4: Settings — gebruik schema/register configuration
- **Spec ref**: specs/deelnames-gebruik/spec.md#requirement-deelnames-query-targets-correct-register-and-schema
- **Files**: lib/Service/SettingsService.php
- [ ] Add "gebruik" to the objectTypes list so gebruik_schema and gebruik_register become configurable

## Task 5: Unit tests
- **Spec ref**: specs/deelnames-gebruik/spec.md (all scenarios)
- **Files**: tests/Unit/Service/ViewServiceTest.php, tests/Unit/Controller/ViewEnrichmentControllerTest.php
- [ ] ViewServiceTest: two-phase retrieval, deduplication, flag guards, graceful error handling
- [ ] ViewEnrichmentControllerTest: endpoint responses, parameter handling, CORS
