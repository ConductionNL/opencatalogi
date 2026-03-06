# OpenCatalogi Integration Tests

This directory contains integration tests for OpenCatalogi functionality. These tests verify the complete workflow of features by making actual API calls to a running Nextcloud instance.

## Prerequisites

Before running integration tests, ensure you have:

1. **Running Docker containers**:
   - Nextcloud container (e.g., 'master-nextcloud-1')
   - MySQL/MariaDB database container
   - Solr search container

2. **OpenCatalogi app installed and enabled**:
   ```bash
   docker exec -u 33 master-nextcloud-1 php occ app:enable opencatalogi
   ```

3. **OpenRegister app installed and enabled**:
   ```bash
   docker exec -u 33 master-nextcloud-1 php occ app:enable openregister
   ```

4. **Admin credentials configured** (default: admin/admin)

## Running the Tests

### Run all integration tests
```bash
# From the opencatalogi app directory
docker exec -u 33 master-nextcloud-1 php vendor/bin/phpunit --testsuite "Integration Tests"
```

### Run a specific test file
```bash
docker exec -u 33 master-nextcloud-1 php vendor/bin/phpunit tests/Integration/CatalogFilteringTest.php
```

### Run a specific test method
```bash
docker exec -u 33 master-nextcloud-1 php vendor/bin/phpunit --filter testGetPublicationsByCatalogSlugReturnsOnlyMatchingPublications tests/Integration/CatalogFilteringTest.php
```

### Run with verbose output
```bash
docker exec -u 33 master-nextcloud-1 php vendor/bin/phpunit --testsuite "Integration Tests" --verbose
```

## Test Coverage

### CatalogFilteringTest.php

Tests the catalog-based publication filtering system:

1. **testGetPublicationsByCatalogSlugReturnsOnlyMatchingPublications**
   - Creates multiple registers and schemas
   - Creates a catalog with specific schemas/registers
   - Creates publications both inside and outside the catalog
   - Verifies the 'GET /api/{catalogSlug}' endpoint returns only matching publications

2. **testGetPublicationByIdValidatesCatalogMembership**
   - Verifies 'GET /api/{catalogSlug}/{id}' returns 200 for publications in the catalog
   - Verifies it returns 404 for publications not in the catalog

3. **testNonExistentCatalogSlugReturns404**
   - Verifies proper error handling for non-existent catalogs

4. **testCatalogWithEmptyFiltersReturnsNoResults**
   - Tests behavior of catalogs with no configured schemas or registers

5. **testCatalogCacheIsInvalidatedOnUpdate**
   - Verifies cache invalidation and warmup on catalog updates

6. **testMultipleSchemasAndRegistersFiltering**
   - Tests filtering across multiple schemas and registers simultaneously

## Important Notes

### API Endpoints

Integration tests call the actual API endpoints:
- **Nextcloud container**: 'http://master-nextcloud-1'
- **NOT localhost**: Tests must run inside the container context

### Cleanup

Tests automatically clean up created resources:
- Publications
- Catalogs
- Schemas
- Registers

If a test fails, some resources might remain. You can manually clean up using:
```bash
# List all registers
docker exec -u 33 master-nextcloud-1 php occ openregister:list

# Delete a specific register (will cascade delete schemas and objects)
docker exec -u 33 master-nextcloud-1 php occ openregister:delete {register-id}
```

### Database State

Integration tests modify the actual database. For production testing:
- Use a separate test instance
- Back up your database before running tests
- Consider using database transactions (if supported by test framework)

### Cache Behavior

Tests verify cache behavior, which requires:
- Redis or APCu configured for distributed caching
- Cache must be enabled in Nextcloud config

## Troubleshooting

### Tests fail with connection errors
- Verify containers are running: 'docker ps'
- Check container name matches configuration (default: 'master-nextcloud-1')
- Verify you are executing from inside the container

### Tests fail with authentication errors
- Verify admin user exists with credentials 'admin:admin'
- Check Nextcloud config.php for correct database settings

### Tests fail with 404 on endpoints
- Verify opencatalogi app is enabled: 'php occ app:list | grep opencatalogi'
- Verify openregister app is enabled: 'php occ app:list | grep openregister'
- Check routes are properly registered in 'appinfo/routes.php'

### Cache-related test failures
- Verify cache is enabled in Nextcloud
- Check Redis/APCu is running and configured
- Try clearing cache: 'php occ cache:clear'

## Contributing

When adding new integration tests:

1. Follow the existing test patterns
2. Use descriptive test method names
3. Add proper docblocks explaining what is being tested
4. Ensure proper cleanup in 'tearDown()'
5. Verify tests can run independently and in any order
6. Update this README with new test descriptions

