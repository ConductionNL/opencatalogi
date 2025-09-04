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
            
            // Build minimal search query - ONLY filter on published
            $searchQuery = $queryParams;
            $searchQuery['_published'] = true;
            $searchQuery['_includeDeleted'] = false;
            
            // Clean up unwanted parameters
            unset($searchQuery['id'], $searchQuery['_route']);
            
            // Add schema/register extension if needed
            if (!isset($searchQuery['_extend'])) {
                $searchQuery['_extend'] = [];
            } elseif (!is_array($searchQuery['_extend'])) {
                $searchQuery['_extend'] = [$searchQuery['_extend']];
            }
            
            // Ensure @self.schema and @self.register are always included for compatibility
            if (!in_array('@self.schema', $searchQuery['_extend'])) {
                $searchQuery['_extend'][] = '@self.schema';
            }
            if (!in_array('@self.register', $searchQuery['_extend'])) {
                $searchQuery['_extend'][] = '@self.register';
            }
            
            // DIRECT ObjectService call - NO FILTERING, NO VALIDATION
            $result = $objectService->searchObjectsPaginated($searchQuery);
            
            // Use pagination directly from ObjectService (it's already paginated!)
            $responseData = [
                'results' => $result['results'] ?? [],
                'total' => $result['total'] ?? 0,
                'limit' => $result['limit'] ?? 20,
                'offset' => $result['offset'] ?? 0,
                'page' => $result['page'] ?? 1,
                'pages' => $result['pages'] ?? 1
            ];
            
            // Add CORS headers for public API access
            $response = new JSONResponse($responseData, 200);
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
            // Normalize to array
            $extend = is_array($extend) ? $extend : [$extend];
            // Filter only values that start with '@self.'
            $extend = array_filter($extend, fn($val) => is_string($val) && str_starts_with($val, '@self.'));
            
            // Ensure @self.schema and @self.register are always included for compatibility
            if (!in_array('@self.schema', $extend)) {
                $extend[] = '@self.schema';
            }
            if (!in_array('@self.register', $extend)) {
                $extend[] = '@self.register';
            }
            
            // DIRECT ObjectService call - NO FILTERING, NO VALIDATION
            $result = $objectService->find(id: $id, extend: $extend);
            
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
            $queryParams = $this->request->getParams();

            // Get the relations for the object directly
            $object = $objectService->find(id: $id);
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
                    'limit' => (int) ($queryParams['_limit'] ?? $queryParams['limit'] ?? 20),
                    'offset' => 0,
                    'facets' => []
                ];
            } else {
                
                // Build search query for the related objects
                $searchQuery = $queryParams;
                $searchQuery['_ids'] = $relations; // Search for objects with these IDs
                $searchQuery['_published'] = true;
                $searchQuery['_includeDeleted'] = false;
                
                // Clean up unwanted parameters
                unset($searchQuery['id'], $searchQuery['_route']);
                
                // Add schema/register extension if needed
                if (!isset($searchQuery['_extend'])) {
                    $searchQuery['_extend'] = [];
                } elseif (!is_array($searchQuery['_extend'])) {
                    $searchQuery['_extend'] = [$searchQuery['_extend']];
                }
                
                // Ensure @self.schema and @self.register are always included for compatibility
                if (!in_array('@self.schema', $searchQuery['_extend'])) {
                    $searchQuery['_extend'][] = '@self.schema';
                }
                if (!in_array('@self.register', $searchQuery['_extend'])) {
                    $searchQuery['_extend'][] = '@self.register';
                }

                // DIRECT ObjectService call for related objects
                $result = $objectService->searchObjectsPaginated($searchQuery);
                
                // Use pagination directly from ObjectService
                $responseData = [
                    'results' => $result['results'] ?? [],
                    'total' => $result['total'] ?? 0,
                    'limit' => $result['limit'] ?? 20,
                    'offset' => $result['offset'] ?? 0,
                    'page' => $result['page'] ?? 1,
                    'pages' => $result['pages'] ?? 1
                ];
                
                // Add pagination links if present
                if (isset($result['next'])) {
                    $responseData['next'] = $result['next'];
                }
                if (isset($result['prev'])) {
                    $responseData['prev'] = $result['prev'];
                }
                
                // Add facets if present (direct passthrough)
                if (isset($result['facets'])) {
                    $facetsData = $result['facets'];
                    // Unwrap nested facets if needed
                    if (isset($facetsData['facets']) && is_array($facetsData['facets'])) {
                        $facetsData = $facetsData['facets'];
                    }
                    $responseData['facets'] = $facetsData;
                }
                if (isset($result['facetable'])) {
                    $responseData['facetable'] = $result['facetable'];
                }
            }
            
            // Add CORS headers for public API access
            $response = new JSONResponse($responseData, 200);
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
            $queryParams = $this->request->getParams();

            // Get objects that have relations pointing to this object
            $relationsArray = $objectService->findByRelations($id);
            $relations = array_map(static fn($relation) => $relation->getUuid(), $relationsArray);

            // Check if relations array is empty
            if (empty($relations)) {
                // If relations is empty, return empty paginated response
                $responseData = [
                    'results' => [],
                    'total' => 0,
                    'page' => 1,
                    'pages' => 1,
                    'limit' => (int) ($queryParams['_limit'] ?? $queryParams['limit'] ?? 20),
                    'offset' => 0,
                    'facets' => []
                ];
            } else {
                
                // Build search query for the objects that reference this publication
                $searchQuery = $queryParams;
                $searchQuery['_ids'] = $relations; // Search for objects with these IDs
                $searchQuery['_published'] = true;
                $searchQuery['_includeDeleted'] = false;
                
                // Clean up unwanted parameters
                unset($searchQuery['id'], $searchQuery['_route']);
                
                // Add schema/register extension if needed
                if (!isset($searchQuery['_extend'])) {
                    $searchQuery['_extend'] = [];
                } elseif (!is_array($searchQuery['_extend'])) {
                    $searchQuery['_extend'] = [$searchQuery['_extend']];
                }
                
                // Ensure @self.schema and @self.register are always included for compatibility
                if (!in_array('@self.schema', $searchQuery['_extend'])) {
                    $searchQuery['_extend'][] = '@self.schema';
                }
                if (!in_array('@self.register', $searchQuery['_extend'])) {
                    $searchQuery['_extend'][] = '@self.register';
                }

                // DIRECT ObjectService call for objects that reference this publication
                $result = $objectService->searchObjectsPaginated($searchQuery);
                
                // Use pagination directly from ObjectService
                $responseData = [
                    'results' => $result['results'] ?? [],
                    'total' => $result['total'] ?? 0,
                    'limit' => $result['limit'] ?? 20,
                    'offset' => $result['offset'] ?? 0,
                    'page' => $result['page'] ?? 1,
                    'pages' => $result['pages'] ?? 1
                ];
                
                // Add pagination links if present
                if (isset($result['next'])) {
                    $responseData['next'] = $result['next'];
                }
                if (isset($result['prev'])) {
                    $responseData['prev'] = $result['prev'];
                }
                
                // Add facets if present (direct passthrough)
                if (isset($result['facets'])) {
                    $facetsData = $result['facets'];
                    // Unwrap nested facets if needed
                    if (isset($facetsData['facets']) && is_array($facetsData['facets'])) {
                        $facetsData = $facetsData['facets'];
                    }
                    $responseData['facets'] = $facetsData;
                }
                if (isset($result['facetable'])) {
                    $responseData['facetable'] = $result['facetable'];
                }
            }
            
            // Add CORS headers for public API access
            $response = new JSONResponse($responseData, 200);
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
