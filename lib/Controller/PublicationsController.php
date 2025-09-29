<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class PublicationsController
 *
 * Controller for handling publication-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class PublicationsController extends Controller
{

    /**
     * @var string Allowed CORS methods
     */
    private string $corsMethods;

    /**
     * @var string Allowed CORS headers
     */
    private string $corsAllowedHeaders;

    /**
     * @var int CORS max age
     */
    private int $corsMaxAge;

    /**
     * PublicationsController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param PublicationService $publicationService The publication service
     * @param DirectoryService   $directoryService   The directory service
     * @param IAppConfig         $config             The app configuration
     * @param ContainerInterface $container          The container for dependency injection
     * @param IAppManager        $appManager         The app manager
     * @param string             $corsMethods        Allowed CORS methods
     * @param string             $corsAllowedHeaders Allowed CORS headers
     * @param int                $corsMaxAge         CORS max age
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly DirectoryService $directoryService,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
        int $corsMaxAge = 1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge = $corsMaxAge;
    }

    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getObjectService()
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return \OCP\AppFramework\Http\Response The CORS response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): \OCP\AppFramework\Http\Response
    {
        // Determine the origin
        $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';

        // Create and configure the response
        $response = new \OCP\AppFramework\Http\Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;
    }

    /**
     * Retrieve all publications from this catalog - DIRECT OBJECTSERVICE HACK
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * Filters only on published=true, no schema/register filtering.
     * 
     * @return JSONResponse JSON response containing publications, pagination info, and optionally facets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        try {
            // Get ObjectService directly - bypass all PublicationService overhead
            $objectService = $this->getObjectService();
            
            // Get query parameters and prepare for direct ObjectService call
            $queryParams = $this->request->getParams();
            
            // Build minimal search query - published filtering handled via method parameter
            $searchQuery = $queryParams;
            $searchQuery['_includeDeleted'] = false;
            
            // Clean up unwanted parameters - published filtering is handled via method parameter, not query
            unset($searchQuery['id'], $searchQuery['_route'], $searchQuery['_published']);
            
            // Add schema/register extension if needed
            if (!isset($searchQuery['_extend'])) {
                $searchQuery['_extend'] = [];
            } elseif (!is_array($searchQuery['_extend'])) {
                // Handle comma-separated strings
                $searchQuery['_extend'] = array_map('trim', explode(',', $searchQuery['_extend']));
            }
            
            // Ensure @self.schema and @self.register are always included for compatibility
            if (!in_array('@self.schema', $searchQuery['_extend'])) {
                $searchQuery['_extend'][] = '@self.schema';
            }
            if (!in_array('@self.register', $searchQuery['_extend'])) {
                $searchQuery['_extend'][] = '@self.register';
            }
            
            // Force use of SOLR index for better performance on public endpoints
            $searchQuery['_source'] = 'index';
            
            // DIRECT ObjectService call - WITH PUBLISHED FILTERING
            // Set rbac=false, multi=false, published=true for public publication access
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery, 
                rbac: false, 
                multi: false, 
                published: true
            );
                     
            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publications: ' . $e->getMessage()], 500);
        }

    }//end index()


    /**
     * Retrieve a specific publication by its ID - DIRECT OBJECTSERVICE HACK
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * No schema/register validation, just direct object lookup.
     *
     * @param  string $id The ID of the publication to retrieve
     * @return JSONResponse JSON response containing the requested publication
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string $id): JSONResponse
    {
        try {
            // Get ObjectService directly - bypass all PublicationService overhead
            $objectService = $this->getObjectService();
            
            // Get request parameters for extensions
            $requestParams = $this->request->getParams();
            
            // Build extend parameters
            $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
            // Normalize to array - handle comma-separated strings
            if (is_string($extend)) {
                $extend = array_map('trim', explode(',', $extend));
            } elseif (!is_array($extend)) {
                $extend = [$extend];
            }

            // Ensure @self.schema and @self.register are always included for compatibility
            if (!in_array('@self.schema', $extend)) {
                $extend[] = '@self.schema';
            }
            if (!in_array('@self.register', $extend)) {
                $extend[] = '@self.register';
            }
            
            // Use searchObjectsPaginated to find single publication with published=true filter
            $searchQuery = [
                '_ids' => [$id],
                '_limit' => 1,
                '_extend' => $extend,
                '_source' => 'index'  // Force use of SOLR index for better performance
            ];
            $searchResult = $objectService->searchObjectsPaginated($searchQuery, rbac: false, multi: false, published: true);
            
            if (empty($searchResult['results'])) {
                return new JSONResponse(['error' => 'Publication not found'], 404);
            }
            
            $result = $searchResult['results'][0];
            
            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
            
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Publication not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publication: ' . $e->getMessage()], 500);
        }

    }//end show()


    /**
     * Retrieve attachments/files of a publication.
     *
     * @param  string $id Id of publication
     *
     * @return JSONResponse JSON response containing the requested attachments/files.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function attachments(string $id): JSONResponse
    {
        return $this->publicationService->attachments(id: $id);

    }//end show()


    /**
     * Retrieve attachments/files of a publication.
     *
     * @param  string $id Id of publication
     *
     * @return JSONResponse JSON response containing the requested attachments/files.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function download(string $id): JSONResponse
    {
        return $this->publicationService->download(id: $id);

    }//end show()


    /**
     * Retrieves all objects that this publication references - DIRECT OBJECTSERVICE HACK
     *
     * This method returns all objects that this publication uses/references. A -> B means that A (This publication) references B (Another object).
     * Bypasses ALL middleware and calls ObjectService directly for maximum performance.
     *
     * @param string $id The ID of the publication to retrieve relations for
     * @return JSONResponse A JSON response containing the related objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function uses(string $id): JSONResponse
    {
        try {
            // Get ObjectService directly - bypass all PublicationService overhead
            $objectService = $this->getObjectService();
            
            // Get query parameters once
            $searchQuery = $this->request->getParams();

            // Get the relations for the object directly using published filter
            $searchQuery = [
                '_ids' => [$id],
                '_limit' => 1,
                '_source' => 'index'  // Force use of SOLR index for better performance
            ];
            $searchResult = $objectService->searchObjectsPaginated($searchQuery, rbac: false, multi: false, published: true);
            
            if (empty($searchResult['results'])) {
                return new JSONResponse(['error' => 'Publication not found'], 404);
            }
            
            $object = $searchResult['results'][0];
            $relationsArray = $object->getRelations();
            $relations = array_values($relationsArray);

            // Check if relations array is empty
            if (empty($relations)) {
                // If relations is empty, return empty paginated response
                $responseData = [
                    'results' => [],
                    'total' => 0,
                    'page' => 1,
                    'pages' => 1,
                    'limit' => (int) ($searchQuery['_limit'] ?? $searchQuery['limit'] ?? 20),
                    'offset' => 0,
                    'facets' => [],
                    // Add relations being searched for debugging
                    'relations' => $relations
                ];
            } else {                
                
                // **CRITICAL FIX**: Create a fresh ObjectService instance for cross-register/schema search
                // After find(), ObjectService is constrained to the object's register/schema
                // But for /uses endpoint, we want to search across ALL registers/schemas
                $freshObjectService = $this->getObjectService();
                
                // Clean up unwanted parameters and remove register/schema restrictions
                // **CRITICAL FIX**: Remove extend parameter - it's for rendering, not filtering
                unset($searchQuery['id'], $searchQuery['_route'], $searchQuery['register'], $searchQuery['schema'], $searchQuery['extend']);

                // Force use of SOLR index for better performance on public endpoints
                $searchQuery['_source'] = 'index';

                // Call fresh ObjectService instance with ids as named parameter
                // Set rbac=false, multi=false, published=true for public publication access
                $result = $freshObjectService->searchObjectsPaginated(
                    query: $searchQuery, 
                    rbac: false, 
                    multi: false, 
                    published: true, 
                    deleted: false,
                    ids: $relations
                );                
            }
            
            // Add what we're searching for in debugging
            $result['relations'] = $relations;
            
            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
            
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Publication not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publication uses: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Retrieves all objects that use this publication - DIRECT OBJECTSERVICE HACK
     *
     * This method returns all objects that reference (use) this publication. B -> A means that B (Another object) references A (This publication).
     * Bypasses ALL middleware and calls ObjectService directly for maximum performance.
     *
     * @param string $id The ID of the publication to retrieve uses for
     * @return JSONResponse A JSON response containing the referenced objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function used(string $id): JSONResponse
    {
        try {
            // Get ObjectService directly - bypass all PublicationService overhead
            $objectService = $this->getObjectService();
            
            // Get query parameters once
            $searchQuery = $this->request->getParams();
            
            // **CRITICAL FIX**: Create a fresh ObjectService instance for cross-register/schema search
            // For /used endpoint, we want to search across ALL registers/schemas
            $freshObjectService = $this->getObjectService();
            
            // Clean up unwanted parameters and remove register/schema restrictions
            // **CRITICAL FIX**: Remove extend parameter - it's for rendering, not filtering
            unset($searchQuery['id'], $searchQuery['_route'], $searchQuery['register'], $searchQuery['schema'], $searchQuery['extend']);
            
            // Force use of SOLR index for better performance on public endpoints
            $searchQuery['_source'] = 'index';
                   
            // Use fresh ObjectService instance searchObjectsPaginated directly - pass uses as named parameter
            // Set rbac=false, multi=false, published=true for public publication access
            $result = $freshObjectService->searchObjectsPaginated(
                query: $searchQuery, 
                rbac: false, 
                multi: false, 
                published: true, 
                deleted: false,
                uses: $id
            );
            
            // Add relations being searched for debugging
            $result['used'] = $id;
            
            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
            
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Publication not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publication used: ' . $e->getMessage()], 500);
        }
    }

}//end class
