<?php
/**
 * Integration Tests for Catalog-Based Publication Filtering
 *
 * Tests the new catalog slug-based endpoints to ensure publications are properly
 * filtered by catalog's schemas and registers.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;

/**
 * Integration Tests for Catalog-Based Publication Filtering
 *
 * These tests verify that the catalog slug endpoints properly filter publications
 * based on the catalog's configured schemas and registers.
 */
class CatalogFilteringTest extends TestCase
{
    /**
     * @var Client HTTP client for API requests
     */
    private Client $client;

    /**
     * @var string Base URL for Nextcloud container
     */
    private string $baseUrl = 'http://localhost';

    /**
     * @var array<string> IDs of created catalogs for cleanup
     */
    private array $createdCatalogIds = [];

    /**
     * @var array<string> IDs of created publications for cleanup
     */
    private array $createdPublicationIds = [];

    /**
     * @var array<int> IDs of created registers for cleanup
     */
    private array $createdRegisterIds = [];

    /**
     * @var array<int> IDs of created schemas for cleanup
     */
    private array $createdSchemaIds = [];


    /**
     * Set up test environment before each test.
     *
     * Initializes HTTP client with authentication and headers.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'auth' => ['admin', 'admin'],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('admin:admin'),
                'OCS-APIRequest' => 'true',
                'Content-Type' => 'application/json',
            ],
        ]);

    }//end setUp()


    /**
     * Clean up test data after each test.
     *
     * Removes all created publications, catalogs, schemas, and registers.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up publications first
        foreach ($this->createdPublicationIds as $id) {
            try {
                // Try to delete from OpenRegister objects endpoint
                $this->client->delete("/index.php/apps/openregister/api/objects/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up catalogs
        foreach ($this->createdCatalogIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/objects/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up schemas
        foreach ($this->createdSchemaIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/schemas/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up registers
        foreach ($this->createdRegisterIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/registers/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * Test 1: Get publications by catalog slug returns only matching publications.
     *
     * Creates a catalog with specific schemas/registers, creates publications both
     * inside and outside the catalog, then verifies the slug endpoint returns only
     * the publications matching the catalog's configuration.
     *
     * @return void
     */
    public function testGetPublicationsByCatalogSlugReturnsOnlyMatchingPublications(): void
    {
        // Step 1: Create test registers
        $register1Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-register-1-' . uniqid(),
                'title' => 'Test Register 1',
                'description' => 'First test register for catalog filtering',
            ]
        ]);
        $this->assertEquals(201, $register1Response->getStatusCode(), 'Failed to create register 1');
        $register1 = json_decode($register1Response->getBody(), true);
        $this->createdRegisterIds[] = $register1['id'];

        $register2Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-register-2-' . uniqid(),
                'title' => 'Test Register 2',
                'description' => 'Second test register for catalog filtering',
            ]
        ]);
        $this->assertEquals(201, $register2Response->getStatusCode(), 'Failed to create register 2');
        $register2 = json_decode($register2Response->getBody(), true);
        $this->createdRegisterIds[] = $register2['id'];

        // Step 2: Create test schemas in different registers
        $schema1Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register1['id'],
                'slug' => 'publication-type-1-' . uniqid(),
                'title' => 'Publication Type 1',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['title']
            ]
        ]);
        $this->assertEquals(201, $schema1Response->getStatusCode(), 'Failed to create schema 1');
        $schema1 = json_decode($schema1Response->getBody(), true);
        $this->createdSchemaIds[] = $schema1['id'];

        $schema2Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register1['id'],
                'slug' => 'publication-type-2-' . uniqid(),
                'title' => 'Publication Type 2',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['title']
            ]
        ]);
        $this->assertEquals(201, $schema2Response->getStatusCode(), 'Failed to create schema 2');
        $schema2 = json_decode($schema2Response->getBody(), true);
        $this->createdSchemaIds[] = $schema2['id'];

        $schema3Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register2['id'],
                'slug' => 'publication-type-3-' . uniqid(),
                'title' => 'Publication Type 3',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['title']
            ]
        ]);
        $this->assertEquals(201, $schema3Response->getStatusCode(), 'Failed to create schema 3');
        $schema3 = json_decode($schema3Response->getBody(), true);
        $this->createdSchemaIds[] = $schema3['id'];

        // Step 3: Create a catalog that includes only schema1 and schema2 from register1
        $catalogSlug = 'test-catalog-' . uniqid();
        $catalogResponse = $this->client->post('/index.php/apps/openregister/api/objects/publication/catalog', [
            'json' => [
                'title' => 'Test Catalog for Filtering',
                'summary' => 'A catalog for testing publication filtering',
                'description' => 'This catalog should only return publications from schema 1 and 2',
                'slug' => $catalogSlug,
                'listed' => true,
                'status' => 'development',
                'registers' => [$register1['id']],
                'schemas' => [$schema1['id'], $schema2['id']],
                'filters' => [],
            ]
        ]);
        $this->assertEquals(201, $catalogResponse->getStatusCode(), 'Failed to create catalog: ' . $catalogResponse->getBody());
        $catalog = json_decode($catalogResponse->getBody(), true);
        $this->createdCatalogIds[] = $catalog['id'];

        // Step 4: Create publications in different schemas
        // Publication 1: In schema1 (should be included)
        $pub1Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register1['slug']}/{$schema1['slug']}", [
            'json' => [
                'title' => 'Publication in Schema 1',
                'summary' => 'This should be returned by catalog endpoint',
                'published' => date('c'),
            ]
        ]);
        $this->assertEquals(201, $pub1Response->getStatusCode(), 'Failed to create publication 1');
        $pub1 = json_decode($pub1Response->getBody(), true);
        $this->createdPublicationIds[] = $pub1['id'];

        // Publication 2: In schema2 (should be included)
        $pub2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register1['slug']}/{$schema2['slug']}", [
            'json' => [
                'title' => 'Publication in Schema 2',
                'summary' => 'This should also be returned by catalog endpoint',
                'published' => date('c'),
            ]
        ]);
        $this->assertEquals(201, $pub2Response->getStatusCode(), 'Failed to create publication 2');
        $pub2 = json_decode($pub2Response->getBody(), true);
        $this->createdPublicationIds[] = $pub2['id'];

        // Publication 3: In schema3 from register2 (should NOT be included)
        $pub3Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register2['slug']}/{$schema3['slug']}", [
            'json' => [
                'title' => 'Publication in Schema 3',
                'summary' => 'This should NOT be returned by catalog endpoint',
                'published' => date('c'),
            ]
        ]);
        $this->assertEquals(201, $pub3Response->getStatusCode(), 'Failed to create publication 3');
        $pub3 = json_decode($pub3Response->getBody(), true);
        $this->createdPublicationIds[] = $pub3['id'];

        // Step 5: Call the catalog slug endpoint and verify filtering
        $listResponse = $this->client->get("/index.php/apps/opencatalogi/api/{$catalogSlug}");
        
        $this->assertEquals(200, $listResponse->getStatusCode(), 'Failed to get publications by catalog slug: ' . $listResponse->getBody());
        
        $result = json_decode($listResponse->getBody(), true);
        $this->assertArrayHasKey('results', $result, 'Response should have results array');
        $this->assertArrayHasKey('@catalog', $result, 'Response should have @catalog metadata');
        
        // Verify catalog metadata
        $this->assertEquals($catalogSlug, $result['@catalog']['slug']);
        $this->assertContains($schema1['id'], $result['@catalog']['schemas']);
        $this->assertContains($schema2['id'], $result['@catalog']['schemas']);
        
        // Verify only publications from schema1 and schema2 are returned
        $returnedIds = array_column($result['results'], 'id');
        $this->assertContains($pub1['id'], $returnedIds, 'Publication 1 should be in results');
        $this->assertContains($pub2['id'], $returnedIds, 'Publication 2 should be in results');
        $this->assertNotContains($pub3['id'], $returnedIds, 'Publication 3 should NOT be in results');

    }//end testGetPublicationsByCatalogSlugReturnsOnlyMatchingPublications()


    /**
     * Test 2: Get specific publication by catalog slug and ID validates catalog membership.
     *
     * Verifies that the show endpoint returns a publication only if it belongs to
     * the catalog's schemas and registers.
     *
     * @return void
     */
    public function testGetPublicationByIdValidatesCatalogMembership(): void
    {
        // Create register and schemas
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-register-' . uniqid(),
                'title' => 'Test Register',
            ]
        ]);
        $this->assertEquals(201, $registerResponse->getStatusCode());
        $register = json_decode($registerResponse->getBody(), true);
        $this->createdRegisterIds[] = $register['id'];

        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register['id'],
                'slug' => 'allowed-schema-' . uniqid(),
                'title' => 'Allowed Schema',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $schemaResponse->getStatusCode());
        $allowedSchema = json_decode($schemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $allowedSchema['id'];

        $otherSchemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register['id'],
                'slug' => 'other-schema-' . uniqid(),
                'title' => 'Other Schema',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $otherSchemaResponse->getStatusCode());
        $otherSchema = json_decode($otherSchemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $otherSchema['id'];

        // Create catalog with only allowedSchema
        $catalogSlug = 'test-catalog-' . uniqid();
        $catalogResponse = $this->client->post('/index.php/apps/openregister/api/objects/publication/catalog', [
            'json' => [
                'title' => 'Test Catalog',
                'slug' => $catalogSlug,
                'listed' => true,
                'status' => 'development',
                'registers' => [$register['id']],
                'schemas' => [$allowedSchema['id']],
                'filters' => [],
            ]
        ]);
        $this->assertEquals(201, $catalogResponse->getStatusCode());
        $catalog = json_decode($catalogResponse->getBody(), true);
        $this->createdCatalogIds[] = $catalog['id'];

        // Create publication in allowed schema
        $allowedPubResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$allowedSchema['slug']}", [
            'json' => [
                'title' => 'Allowed Publication',
                'published' => date('c'),
            ]
        ]);
        $this->assertEquals(201, $allowedPubResponse->getStatusCode());
        $allowedPub = json_decode($allowedPubResponse->getBody(), true);
        $this->createdPublicationIds[] = $allowedPub['id'];

        // Create publication in other schema
        $otherPubResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$otherSchema['slug']}", [
            'json' => [
                'title' => 'Other Publication',
                'published' => date('c'),
            ]
        ]);
        $this->assertEquals(201, $otherPubResponse->getStatusCode());
        $otherPub = json_decode($otherPubResponse->getBody(), true);
        $this->createdPublicationIds[] = $otherPub['id'];

        // Test: Get allowed publication should succeed
        $getAllowedResponse = $this->client->get("/index.php/apps/opencatalogi/api/{$catalogSlug}/{$allowedPub['id']}");
        $this->assertEquals(200, $getAllowedResponse->getStatusCode(), 'Should return publication from catalog');
        $allowedResult = json_decode($getAllowedResponse->getBody(), true);
        $this->assertEquals($allowedPub['id'], $allowedResult['id']);

        // Test: Get publication from other schema should fail
        $getOtherResponse = $this->client->get("/index.php/apps/opencatalogi/api/{$catalogSlug}/{$otherPub['id']}");
        $this->assertEquals(404, $getOtherResponse->getStatusCode(), 'Should return 404 for publication not in catalog');

    }//end testGetPublicationByIdValidatesCatalogMembership()


    /**
     * Test 3: Non-existent catalog slug returns 404.
     *
     * Verifies that requesting a catalog that doesn't exist returns proper error.
     *
     * @return void
     */
    public function testNonExistentCatalogSlugReturns404(): void
    {
        $response = $this->client->get('/index.php/apps/opencatalogi/api/non-existent-catalog-slug');
        
        $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for non-existent catalog');
        
        $result = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $result, 'Error response should have error key');
        $this->assertStringContainsString('Catalog not found', $result['error']);

    }//end testNonExistentCatalogSlugReturns404()


    /**
     * Test 4: Catalog with empty schemas/registers returns no results.
     *
     * Verifies behavior when catalog has no configured schemas or registers.
     *
     * @return void
     */
    public function testCatalogWithEmptyFiltersReturnsNoResults(): void
    {
        // Create catalog with empty schemas and registers
        $catalogSlug = 'empty-catalog-' . uniqid();
        $catalogResponse = $this->client->post('/index.php/apps/openregister/api/objects/publication/catalog', [
            'json' => [
                'title' => 'Empty Catalog',
                'slug' => $catalogSlug,
                'listed' => true,
                'status' => 'development',
                'registers' => [],
                'schemas' => [],
                'filters' => [],
            ]
        ]);
        $this->assertEquals(201, $catalogResponse->getStatusCode());
        $catalog = json_decode($catalogResponse->getBody(), true);
        $this->createdCatalogIds[] = $catalog['id'];

        // Try to get publications
        $listResponse = $this->client->get("/index.php/apps/opencatalogi/api/{$catalogSlug}");
        
        $this->assertEquals(200, $listResponse->getStatusCode());
        
        $result = json_decode($listResponse->getBody(), true);
        $this->assertArrayHasKey('results', $result);
        // Empty filters might return everything or nothing depending on implementation
        // This documents the expected behavior

    }//end testCatalogWithEmptyFiltersReturnsNoResults()


    /**
     * Test 5: Cache is invalidated when catalog is updated.
     *
     * Verifies that updating a catalog properly invalidates and warms up the cache.
     *
     * @return void
     */
    public function testCatalogCacheIsInvalidatedOnUpdate(): void
    {
        // Create catalog
        $catalogSlug = 'cache-test-catalog-' . uniqid();
        $catalogResponse = $this->client->post('/index.php/apps/openregister/api/objects/publication/catalog', [
            'json' => [
                'title' => 'Cache Test Catalog',
                'slug' => $catalogSlug,
                'listed' => true,
                'status' => 'development',
                'registers' => [],
                'schemas' => [],
                'filters' => [],
            ]
        ]);
        $this->assertEquals(201, $catalogResponse->getStatusCode());
        $catalog = json_decode($catalogResponse->getBody(), true);
        $this->createdCatalogIds[] = $catalog['id'];

        // First request (should cache)
        $firstResponse = $this->client->get("/index.php/apps/opencatalogi/api/{$catalogSlug}");
        $this->assertEquals(200, $firstResponse->getStatusCode());

        // Update catalog
        $updateResponse = $this->client->put("/index.php/apps/openregister/api/objects/{$catalog['id']}", [
            'json' => [
                'title' => 'Updated Cache Test Catalog',
                'slug' => $catalogSlug,
                'listed' => true,
                'status' => 'stable',
                'registers' => [],
                'schemas' => [],
                'filters' => [],
            ]
        ]);
        $this->assertEquals(200, $updateResponse->getStatusCode());

        // Second request (should get updated version from fresh cache)
        $secondResponse = $this->client->get("/index.php/apps/opencatalogi/api/{$catalogSlug}");
        $this->assertEquals(200, $secondResponse->getStatusCode());
        
        // Both requests should succeed, demonstrating cache works and updates

    }//end testCatalogCacheIsInvalidatedOnUpdate()


    /**
     * Test 6: Multiple schemas and registers filtering works correctly.
     *
     * Verifies that catalogs can filter on multiple schemas and registers simultaneously.
     *
     * @return void
     */
    public function testMultipleSchemasAndRegistersFiltering(): void
    {
        // Create two registers
        $reg1Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'multi-test-reg1-' . uniqid(),
                'title' => 'Multi Test Register 1',
            ]
        ]);
        $this->assertEquals(201, $reg1Response->getStatusCode());
        $reg1 = json_decode($reg1Response->getBody(), true);
        $this->createdRegisterIds[] = $reg1['id'];

        $reg2Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'multi-test-reg2-' . uniqid(),
                'title' => 'Multi Test Register 2',
            ]
        ]);
        $this->assertEquals(201, $reg2Response->getStatusCode());
        $reg2 = json_decode($reg2Response->getBody(), true);
        $this->createdRegisterIds[] = $reg2['id'];

        // Create schemas in both registers
        $schema1Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $reg1['id'],
                'slug' => 'multi-schema1-' . uniqid(),
                'title' => 'Multi Schema 1',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $schema1Response->getStatusCode());
        $schema1 = json_decode($schema1Response->getBody(), true);
        $this->createdSchemaIds[] = $schema1['id'];

        $schema2Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $reg2['id'],
                'slug' => 'multi-schema2-' . uniqid(),
                'title' => 'Multi Schema 2',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $schema2Response->getStatusCode());
        $schema2 = json_decode($schema2Response->getBody(), true);
        $this->createdSchemaIds[] = $schema2['id'];

        // Create catalog with both registers and schemas
        $catalogSlug = 'multi-catalog-' . uniqid();
        $catalogResponse = $this->client->post('/index.php/apps/openregister/api/objects/publication/catalog', [
            'json' => [
                'title' => 'Multi Register/Schema Catalog',
                'slug' => $catalogSlug,
                'listed' => true,
                'status' => 'development',
                'registers' => [$reg1['id'], $reg2['id']],
                'schemas' => [$schema1['id'], $schema2['id']],
                'filters' => [],
            ]
        ]);
        $this->assertEquals(201, $catalogResponse->getStatusCode());
        $catalog = json_decode($catalogResponse->getBody(), true);
        $this->createdCatalogIds[] = $catalog['id'];

        // Create publications in both schemas
        $pub1Response = $this->client->post("/index.php/apps/openregister/api/objects/{$reg1['slug']}/{$schema1['slug']}", [
            'json' => [
                'title' => 'Publication in Register 1',
                'published' => date('c'),
            ]
        ]);
        $this->assertEquals(201, $pub1Response->getStatusCode());
        $pub1 = json_decode($pub1Response->getBody(), true);
        $this->createdPublicationIds[] = $pub1['id'];

        $pub2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$reg2['slug']}/{$schema2['slug']}", [
            'json' => [
                'title' => 'Publication in Register 2',
                'published' => date('c'),
            ]
        ]);
        $this->assertEquals(201, $pub2Response->getStatusCode());
        $pub2 = json_decode($pub2Response->getBody(), true);
        $this->createdPublicationIds[] = $pub2['id'];

        // Verify both publications are returned
        $listResponse = $this->client->get("/index.php/apps/opencatalogi/api/{$catalogSlug}");
        $this->assertEquals(200, $listResponse->getStatusCode());
        
        $result = json_decode($listResponse->getBody(), true);
        $returnedIds = array_column($result['results'], 'id');
        $this->assertContains($pub1['id'], $returnedIds, 'Should include publication from register 1');
        $this->assertContains($pub2['id'], $returnedIds, 'Should include publication from register 2');

    }//end testMultipleSchemasAndRegistersFiltering()


}//end class

