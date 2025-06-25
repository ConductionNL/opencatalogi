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
     * Retrieve a list of publications based on all available catalogs.
     *
     * This method returns publications from the local catalog and optionally
     * from federated catalogs if _aggregate parameter is not set to false.
     * When aggregating, it prevents circular references by setting _aggregate=false
     * on outgoing requests.
     *
     * @param  string|int|null $catalogId Optional ID of a specific catalog to filter by
     * @return JSONResponse JSON response containing the list of publications and total count
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
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
        
        // Check if aggregation is enabled (default: true, unless explicitly set to false)
        $aggregate = $this->request->getParam('_aggregate', 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';
        
        // Get local publications first
        $localResponse = $this->publicationService->index();
        
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
            
            // Allow custom headers via query parameter (JSON encoded)
            if ($this->request->getParam('headers')) {
                $headers = json_decode($this->request->getParam('headers'), true);
                if (is_array($headers)) {
                    $guzzleConfig['headers'] = array_merge(
                        $guzzleConfig['headers'] ?? [],
                        $headers
                    );
                }
            }
            
            // Pass through current query parameters (DirectoryService will handle _aggregate and _extend)
            //$queryParams = $_GET; // Get all current query parameters
            $guzzleConfig['query_params'] = $queryParams;

            // Get aggregated publications from directory service
            $federationResult = $this->directoryService->getPublications($guzzleConfig);
            
            // Decode local response to merge with aggregated results
            $localData = json_decode($localResponse->render(), true);
            
            // Merge local and aggregated results
            $mergedResults = [
                'results' => array_merge(
                    $localData['results'] ?? [],
                    $federationResult['results'] ?? []
                ),
                'total' => ($localData['total'] ?? 0) + count($federationResult['results'] ?? []),
                'sources' => array_merge(
                    ['local' => 'Local OpenCatalogi instance'],
                    $federationResult['sources'] ?? []
                )
            ];
            
            // Set appropriate HTTP status based on results
            $statusCode = 200;
            
            // Add CORS headers for public API access
            $response = new JSONResponse($mergedResults, $statusCode);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
            
            return $response;
            
        } catch (\Exception $e) {
            // If aggregation fails, return local results only
            return $localResponse;
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

}//end class
