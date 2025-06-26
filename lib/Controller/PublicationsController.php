<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
     * @param string             $corsMethods        Allowed CORS methods
     * @param string             $corsAllowedHeaders Allowed CORS headers
     * @param int                $corsMaxAge         CORS max age
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly DirectoryService $directoryService,
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
     * Retrieve all publications from this catalog and optionally from federated catalogs.
     *
     * This method handles both local and aggregated search results when the _aggregate
     * parameter is not set to false. It supports faceting when _facetable parameter is provided.
     * 
     * AGGREGATION FEATURES:
     * - Proper pagination: Collects sufficient data from all sources, merges and deduplicates,
     *   then applies pagination to ensure consistent totals and page counts
     * - Ordering: Supports _order parameters like _order[@self.published]=DESC to sort the
     *   combined dataset from all sources according to specified criteria
     * - Deduplication: Removes duplicate entries based on object ID across all sources
     * - Faceting: Merges facet data from multiple sources when _facetable=true
     *
     * @return JSONResponse JSON response containing publications, pagination info, and optionally facets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        //@todo this is a temporary fix to map the parameters to _extend format
        // Define parameters that should be mapped to _extend format
        $parametersToMap = ['extend', 'fields', 'facets','order','page','limit'];
        
        // Get all current query parameters
        $queryParams = $this->request->getParams();
        
        // Map specified parameters to _extend format and unset originals
        foreach ($parametersToMap as $param) {
            if (isset($queryParams[$param])) {
                // Map the parameter to _extend format
                $queryParams['_extend'] = $queryParams[$param];
                // Unset the original parameter to prevent conflicts
                unset($queryParams[$param]);
            }
        }
        
        // Extract pagination parameters
        $limit = (int) ($this->request->getParam('_limit') ?? $this->request->getParam('limit') ?? 20);
        $page = (int) ($this->request->getParam('_page') ?? $this->request->getParam('page') ?? 1);
        $offset = (int) ($this->request->getParam('offset') ?? (($page - 1) * $limit));
        
        // Ensure minimum values
        $limit = max(1, $limit);
        $page = max(1, $page);
        $offset = max(0, $offset);
        
        // Add pagination parameters to query
        $queryParams['_limit'] = $limit;
        $queryParams['_page'] = $page;
        if ($offset > 0) {
            $queryParams['offset'] = $offset;
        }
        
        // Check if aggregation is enabled (default: true, unless explicitly set to false)
        $aggregate = $this->request->getParam('_aggregate', 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';
        
        // Check if faceting is requested
        $facetable = $this->request->getParam('_facetable');
        $shouldIncludeFacets = ($facetable === 'true' || $facetable === true);
        
        // Always add _extend parameters for schema and register information
        if (!isset($queryParams['_extend'])) {
            $queryParams['_extend'] = [];
        } elseif (!is_array($queryParams['_extend'])) {
            $queryParams['_extend'] = [$queryParams['_extend']];
        }
        
        // Ensure @self.schema and @self.register are always included
        if (!in_array('@self.schema', $queryParams['_extend'])) {
            $queryParams['_extend'][] = '@self.schema';
        }
        if (!in_array('@self.register', $queryParams['_extend'])) {
            $queryParams['_extend'][] = '@self.register';
        }
        
        // Get local publications first
        $localResponse = $this->publicationService->index();
        $localData = json_decode($localResponse->render(), true);
        
        // Calculate pagination info
        $totalResults = ($localData['total'] ?? 0);
        $totalPages = $limit > 0 ? max(1, ceil($totalResults / $limit)) : 1;
        
        // Initialize response structure with pagination
        $responseData = [
            'results' => $localData['results'] ?? [],
            'total' => $totalResults,
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'pages' => $totalPages
        ];
        
        // Add pagination links
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $baseUrl = $protocol . '://' . $host . strtok($uri, '?');
        
        if ($page < $totalPages) {
            $nextParams = $this->request->getParams();
            $nextParams['_page'] = $page + 1;
            $responseData['next'] = $baseUrl . '?' . http_build_query($nextParams);
        }
        
        if ($page > 1) {
            $prevParams = $this->request->getParams();
            $prevParams['_page'] = $page - 1;
            $responseData['prev'] = $baseUrl . '?' . http_build_query($prevParams);
        }
        
        // Store sources information for facetable
        $sources = ['local' => 'Local OpenCatalogi instance'];
        
        // Add facets and facetable from local service if present
        if (isset($localData['facets'])) {
            // Check if facets are nested (unwrap if needed)
            $facetsData = $localData['facets'];
            if (isset($facetsData['facets']) && is_array($facetsData['facets'])) {
                $facetsData = $facetsData['facets'];
            }
            $responseData['facets'] = $facetsData;
        }
        if (isset($localData['facetable'])) {
            $responseData['facetable'] = $this->mergeFacetableData($localData['facetable'], [], $sources);
        } elseif ($shouldIncludeFacets) {
            // If faceting is requested but no facetable data exists, create basic structure
            $responseData['facetable'] = $this->mergeFacetableData([], [], $sources);
        }
        
        // If aggregation is disabled, return only local results
        if (!$shouldAggregate) {
            // Set appropriate HTTP status based on results
            $statusCode = 200;
            
            // Add CORS headers for public API access
            $response = new JSONResponse($responseData, $statusCode);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
        }
        
        try {
            /**
             * AGGREGATION PAGINATION FIX
             * 
             * When aggregating results from multiple sources (local + federated), we cannot
             * simply request the same page from each source and merge the results, because:
             * 
             * 1. Each source has different data, so page 2 from Source A + page 2 from Source B
             *    does not equal page 2 of the merged dataset
             * 2. This causes inconsistent totals between pages (e.g., page 1 shows total=22, 
             *    page 2 shows total=20)
             * 3. Users may see missing results or empty pages
             * 
             * SOLUTION:
             * 1. Calculate how many items we need: requestedPage * itemsPerPage
             * 2. Request that many items from page 1 of ALL sources
             * 3. Merge and deduplicate all collected results
             * 4. Apply pagination to the merged dataset to get the correct slice
             * 
             * Example: User requests page 2 with limit 20
             * - We request 40 items (2*20) from page 1 of each source
             * - Merge all results, remove duplicates
             * - Take items 21-40 from the merged list (page 2)
             * 
             * This ensures consistent pagination and totals across all pages.
             */
            
            // For aggregation, we need to collect enough data from all sources
            // to properly paginate the merged results
            
            // Calculate how many items we need to collect from each source
            // to ensure we have enough data for the requested page
            // Example: page 3 with limit 10 = need 30 items to get items 21-30
            $itemsNeeded = $page * $limit;
            
            // Prepare query parameters for fetching from sources
            $localQueryParams = $queryParams;
            $federatedQueryParams = $queryParams;
            
            // For local results: request from page 1 with enough items
            $localQueryParams['_page'] = 1;
            $localQueryParams['_limit'] = $itemsNeeded;
            unset($localQueryParams['offset']); // Remove offset to start from beginning
            
            // For federated results: request from page 1 with enough items
            $federatedQueryParams['_page'] = 1;
            $federatedQueryParams['_limit'] = $itemsNeeded;
            unset($federatedQueryParams['offset']); // Remove offset to start from beginning
            
            // Get local results with modified parameters
            // Pass the modified parameters directly to the service
            $allLocalResponse = $this->publicationService->index(null, $localQueryParams);
            $allLocalData = json_decode($allLocalResponse->render(), true);
            
            // Get federated results with modified parameters
            $federationResult = $this->directoryService->getPublications($federatedQueryParams);
            
            // Merge local and federated results
            $allResults = array_merge(
                $allLocalData['results'] ?? [],
                $federationResult['results'] ?? []
            );
            
            // Remove duplicates based on ID
            $uniqueResults = [];
            $seenIds = [];
            foreach ($allResults as $result) {
                $id = $result['id'] ?? $result['uuid'] ?? uniqid();
                if (!isset($seenIds[$id])) {
                    $uniqueResults[] = $result;
                    $seenIds[$id] = true;
                }
            }
            
            // Apply ordering to the merged and deduplicated results
            // This is crucial for aggregation because each source may have different ordering,
            // so we need to re-sort the combined dataset according to the requested criteria
            // Supports formats like: _order[@self.published]=DESC, _order[title]=ASC, etc.
            $uniqueResults = $this->applyCumulativeOrdering($uniqueResults, $queryParams);
            
            // Apply pagination to the merged results
            $totalResults = count($uniqueResults);
            $totalPages = $limit > 0 ? max(1, ceil($totalResults / $limit)) : 1;
            
            // Calculate the correct slice for this page
            $startIndex = ($page - 1) * $limit;
            $paginatedResults = array_slice($uniqueResults, $startIndex, $limit);
            
            // Update response with paginated combined data
            $responseData = [
                'results' => $paginatedResults,
                'total' => $totalResults,
                'limit' => $limit,
                'offset' => $startIndex,
                'page' => $page,
                'pages' => $totalPages
            ];
            
            // Update pagination links
            if ($page < $totalPages) {
                $nextParams = $this->request->getParams();
                $nextParams['_page'] = $page + 1;
                $responseData['next'] = $baseUrl . '?' . http_build_query($nextParams);
            }
            
            if ($page > 1) {
                $prevParams = $this->request->getParams();
                $prevParams['_page'] = $page - 1;
                $responseData['prev'] = $baseUrl . '?' . http_build_query($prevParams);
            }
            
            // Update sources with federated information
            $sources = array_merge($sources, $federationResult['sources'] ?? []);
            
            // Merge facets and facetable data if present
            if (isset($allLocalData['facets']) || isset($federationResult['facets'])) {
                $localFacets = $allLocalData['facets'] ?? [];
                $federatedFacets = $federationResult['facets'] ?? [];
                
                // Check if facets are nested and unwrap if needed
                if (isset($localFacets['facets']) && is_array($localFacets['facets'])) {
                    $localFacets = $localFacets['facets'];
                }
                if (isset($federatedFacets['facets']) && is_array($federatedFacets['facets'])) {
                    $federatedFacets = $federatedFacets['facets'];
                }
                
                $responseData['facets'] = $this->mergeFacetsData($localFacets, $federatedFacets);
            }
            
            if (isset($allLocalData['facetable']) || isset($federationResult['facetable'])) {
                $responseData['facetable'] = $this->mergeFacetableData(
                    $allLocalData['facetable'] ?? [], 
                    $federationResult['facetable'] ?? [], 
                    $sources
                );
            } elseif ($shouldIncludeFacets) {
                $responseData['facetable'] = $this->mergeFacetableData([], [], $sources);
            }
            
            // Set appropriate HTTP status based on results
            $statusCode = 200;
            
            // Add CORS headers for public API access
            $response = new JSONResponse($responseData, $statusCode);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
            
        } catch (\Exception $e) {
            // If aggregation fails, return local results only
            // Set appropriate HTTP status based on results
            $statusCode = 200;
            
            // Add CORS headers for public API access
            $response = new JSONResponse($responseData, $statusCode);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
        }

    }//end index()


    /**
     * Retrieve a specific publication by its ID.
     *
     * This method searches for a publication in the local catalog first,
     * and optionally in federated catalogs if _aggregate parameter is not
     * set to false and the publication is not found locally.
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
        // Try to get the publication locally first
        $localResponse = $this->publicationService->show(id: $id);
        $localData = json_decode($localResponse->render(), true);
        
        // If found locally, return it (unless we want to also search federally for additional data)
        if ($localResponse->getStatus() === 200 && !empty($localData)) {
            // Check if aggregation is enabled for enrichment
            $aggregate = $this->request->getParam('_aggregate', 'true');
            $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';
            
            if (!$shouldAggregate) {
                return $localResponse;
            }
            
            // Add source information to local result
            $localData['sources'] = ['local' => 'Local OpenCatalogi instance'];
            return new JSONResponse($localData, 200);
        }
        
        // Check if aggregation is enabled for federation search
        $aggregate = $this->request->getParam('_aggregate', 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';
        
        // If aggregation is disabled and not found locally, return 404
        if (!$shouldAggregate) {
            return $localResponse; // Return the original 404 response
        }
        
        try {
            // Search in federated catalogs
            $guzzleConfig = [];
            
            // Allow timeout configuration via query parameter
            if ($this->request->getParam('timeout')) {
                $timeout = (int) $this->request->getParam('timeout');
                if ($timeout > 0 && $timeout <= 120) { // Max 2 minutes
                    $guzzleConfig['timeout'] = $timeout;
                }
            }
            
            // Allow connect timeout configuration via query parameter
            if ($this->request->getParam('connect_timeout')) {
                $connectTimeout = (int) $this->request->getParam('connect_timeout');
                if ($connectTimeout > 0 && $connectTimeout <= 30) { // Max 30 seconds
                    $guzzleConfig['connect_timeout'] = $connectTimeout;
                }
            }
            
            // Pass through current query parameters (DirectoryService will handle _aggregate and _extend)
            $queryParams = $_GET; // Get all current query parameters
            $guzzleConfig['query_params'] = $queryParams;

            // Get publication from directory service
            $federatedResult = $this->directoryService->getPublication($id, $guzzleConfig);
            
            if (!empty($federatedResult) && isset($federatedResult['result'])) {
                // Merge the result with source information
                $responseData = $federatedResult['result'];
                $responseData['sources'] = $federatedResult['source'] ?? [];
                
                // Add CORS headers for public API access
                $response = new JSONResponse($responseData, 200);
                $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
                $response->addHeader('Access-Control-Allow-Origin', $origin);
                $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
                $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
                
                return $response;
            }
            
            // Not found in federated catalogs either, return 404
            return new JSONResponse(['error' => 'Publication not found'], 404);
            
        } catch (\Exception $e) {
            // If federation search fails, return the original local response (likely 404)
            return $localResponse;
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
     * Retrieves all objects that this publication references
     *
     * This method returns all objects that this publication uses/references. A -> B means that A (This publication) references B (Another object).
     * When aggregation is enabled, it also searches federated catalogs.
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
        // Check if aggregation is enabled (default: true, unless explicitly set to false)
        $aggregate = $this->request->getParam('_aggregate', 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';
        
        // Get local results first
        $localResponse = $this->publicationService->uses(id: $id);
        
        // If aggregation is disabled, return only local results
        if (!$shouldAggregate) {
            return $localResponse;
        }
        
        try {
            // Get optional Guzzle configuration from request parameters
            $guzzleConfig = [];
            
            // Allow timeout configuration via query parameter
            if ($this->request->getParam('timeout')) {
                $timeout = (int) $this->request->getParam('timeout');
                if ($timeout > 0 && $timeout <= 120) { // Max 2 minutes
                    $guzzleConfig['timeout'] = $timeout;
                }
            }
            
            // Allow connect timeout configuration via query parameter
            if ($this->request->getParam('connect_timeout')) {
                $connectTimeout = (int) $this->request->getParam('connect_timeout');
                if ($connectTimeout > 0 && $connectTimeout <= 30) { // Max 30 seconds
                    $guzzleConfig['connect_timeout'] = $connectTimeout;
                }
            }
            
            // Pass through current query parameters (DirectoryService will handle _aggregate and _extend)
            $queryParams = $_GET; // Get all current query parameters
            $guzzleConfig['query_params'] = $queryParams;

            // Note: For 'uses' we don't have a specific DirectoryService method yet
            // This would need to be implemented similar to getUsed() if needed
            // For now, return local results only
            return $localResponse;
            
        } catch (\Exception $e) {
            // If federation fails, return local results
            return $localResponse;
        }
    }


    /**
     * Retrieves all objects that use this publication
     *
     * This method returns all objects that reference (use) this publication. B -> A means that B (Another object) references A (This publication).
     * When aggregation is enabled, it also searches federated catalogs.
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
        // Check if aggregation is enabled (default: true, unless explicitly set to false)
        $aggregate = $this->request->getParam('_aggregate', 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';
        
        // Get local results first
        $localResponse = $this->publicationService->used(id: $id);
        
        // If aggregation is disabled, return only local results
        if (!$shouldAggregate) {
            return $localResponse;
        }
        
        try {
            // Get optional Guzzle configuration from request parameters
            $guzzleConfig = [];
            
            // Allow timeout configuration via query parameter
            if ($this->request->getParam('timeout')) {
                $timeout = (int) $this->request->getParam('timeout');
                if ($timeout > 0 && $timeout <= 120) { // Max 2 minutes
                    $guzzleConfig['timeout'] = $timeout;
                }
            }
            
            // Allow connect timeout configuration via query parameter
            if ($this->request->getParam('connect_timeout')) {
                $connectTimeout = (int) $this->request->getParam('connect_timeout');
                if ($connectTimeout > 0 && $connectTimeout <= 30) { // Max 30 seconds
                    $guzzleConfig['connect_timeout'] = $connectTimeout;
                }
            }
            
            // Pass through current query parameters (DirectoryService will handle _aggregate and _extend)
            $queryParams = $_GET; // Get all current query parameters
            $guzzleConfig['query_params'] = $queryParams;

            // Get federated results from directory service
            $federatedResults = $this->directoryService->getUsed($id, $guzzleConfig);
            
            // Decode local response to merge with federated results
            $localData = json_decode($localResponse->render(), true);
            
            // Merge local and federated results
            $mergedResults = [
                'results' => array_merge(
                    $localData ?? [],
                    $federatedResults['results'] ?? []
                ),
                'sources' => array_merge(
                    ['local' => 'Local OpenCatalogi instance'],
                    $federatedResults['sources'] ?? []
                )
            ];
            
            // Add CORS headers for public API access
            $response = new JSONResponse($mergedResults, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
            
        } catch (\Exception $e) {
            // If federation fails, return local results
            return $localResponse;
        }
    }

    /**
     * Simple merge function for facets data from multiple sources.
     *
     * @param array $localFacets Facets from local source
     * @param array $federatedFacets Facets from federated sources
     * @return array Merged facets data
     */
    private function mergeFacetsData(array $localFacets, array $federatedFacets): array
    {
        // For now, just return local facets as they're more reliable
        // In the future, we could implement more sophisticated merging
        return !empty($localFacets) ? $localFacets : $federatedFacets;
    }
    
    /**
     * Simple merge function for facetable metadata from multiple sources.
     *
     * @param array $localFacetable Facetable metadata from local source
     * @param array $federatedFacetable Facetable metadata from federated sources
     * @param array $sources Sources information
     * @return array Merged facetable metadata
     */
    private function mergeFacetableData(array $localFacetable, array $federatedFacetable, array $sources): array
    {
        // Start with local facetable as base
        $mergedFacetable = !empty($localFacetable) ? $localFacetable : $federatedFacetable;
        
        // Ensure @self section exists
        if (!isset($mergedFacetable['@self'])) {
            $mergedFacetable['@self'] = [];
        }
        
        // Add catalog facet based on sources
        $catalogSamples = [];
        foreach ($sources as $key => $url) {
            $catalogSamples[] = [
                'value' => $key,
                'label' => $key === 'local' ? 'Local OpenCatalogi instance' : $key,
                'count' => 1 // This would need actual counting in a real implementation
            ];
        }
        
        $mergedFacetable['@self']['catalog'] = [
            'type' => 'categorical',
            'description' => 'Catalog source of the publication',
            'facet_types' => ['terms'],
            'has_labels' => true,
            'sample_values' => $catalogSamples
        ];
        
        // Add organisation facet based on sources
        $organisationSamples = [];
        foreach ($sources as $key => $url) {
            if ($key !== 'local') {
                // Extract organisation from domain name
                $domain = parse_url($url, PHP_URL_HOST) ?? $key;
                $organisationSamples[] = [
                    'value' => $domain,
                    'label' => ucfirst(str_replace(['.', '_', '-'], ' ', $domain)),
                    'count' => 1
                ];
            } else {
                $organisationSamples[] = [
                    'value' => 'local',
                    'label' => 'Local Organisation',
                    'count' => 1
                ];
            }
        }
        
        $mergedFacetable['@self']['organisation'] = [
            'type' => 'categorical',
            'description' => 'Organisation that published the content',
            'facet_types' => ['terms'],
            'has_labels' => true,
            'sample_values' => $organisationSamples
        ];
        
        return $mergedFacetable;
    }

    /**
     * Apply ordering to the cumulated dataset from multiple sources
     *
     * This method handles ordering parameters in the format _order[field]=direction
     * and applies them to the merged results from local and federated sources.
     * Since each source may have different ordering, we need to re-sort the combined dataset.
     *
     * @param array $results The merged and deduplicated results to order
     * @param array $queryParams The query parameters containing ordering instructions
     * @return array The ordered results
     */
    private function applyCumulativeOrdering(array $results, array $queryParams): array
    {
        // Extract ordering parameters
        $orderParams = $queryParams['_order'] ?? $queryParams['order'] ?? [];
        
        if (empty($orderParams) || !is_array($orderParams)) {
            return $results;
        }
        
        // Convert single field ordering to array format for consistency
        if (!isset($orderParams[0])) {
            $orderParams = [$orderParams];
        }
        
        // Apply multiple field ordering (PHP's usort is stable for equal values)
        usort($results, function($a, $b) use ($orderParams) {
            foreach ($orderParams as $field => $direction) {
                // Handle both associative array format: ['field' => 'direction']
                // and indexed array format: [0 => ['field' => 'direction']]
                if (is_numeric($field) && is_array($direction)) {
                    // Format: [0 => ['@self.published' => 'DESC']]
                    $fieldName = array_key_first($direction);
                    $sortDirection = strtoupper($direction[$fieldName] ?? 'ASC');
                } else {
                    // Format: ['@self.published' => 'DESC']
                    $fieldName = $field;
                    $sortDirection = strtoupper($direction ?? 'ASC');
                }
                
                // Extract values for comparison
                $valueA = $this->extractFieldValue($a, $fieldName);
                $valueB = $this->extractFieldValue($b, $fieldName);
                
                // Compare values
                $comparison = $this->compareValues($valueA, $valueB);
                
                if ($comparison !== 0) {
                    // Return result based on sort direction
                    return $sortDirection === 'DESC' ? -$comparison : $comparison;
                }
                
                // If values are equal, continue to next sort field
            }
            
            return 0; // All compared fields are equal
        });
        
        return $results;
    }
    
    /**
     * Extract field value from a result object using dot notation
     *
     * Supports nested field access like '@self.published' or 'data.title'
     *
     * @param array $result The result object to extract value from
     * @param string $fieldPath The field path in dot notation
     * @return mixed The extracted value or null if not found
     */
    private function extractFieldValue(array $result, string $fieldPath)
    {
        $parts = explode('.', $fieldPath);
        $value = $result;
        
        foreach ($parts as $part) {
            if (!is_array($value) || !isset($value[$part])) {
                return null;
            }
            $value = $value[$part];
        }
        
        return $value;
    }
    
    /**
     * Compare two values for sorting
     *
     * Handles different data types appropriately for sorting
     *
     * @param mixed $a First value
     * @param mixed $b Second value
     * @return int -1, 0, or 1 for less than, equal, or greater than
     */
    private function compareValues($a, $b): int
    {
        // Handle null values
        if ($a === null && $b === null) return 0;
        if ($a === null) return -1;
        if ($b === null) return 1;
        
        // Handle date strings
        if (is_string($a) && is_string($b)) {
            $dateA = strtotime($a);
            $dateB = strtotime($b);
            
            if ($dateA !== false && $dateB !== false) {
                return $dateA <=> $dateB;
            }
        }
        
        // Handle numeric values
        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }
        
        // Handle string comparison
        return strcmp((string)$a, (string)$b);
    }

}//end class
