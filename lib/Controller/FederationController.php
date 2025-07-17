<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class FederationController
 *
 * Controller for handling federation endpoints that provide access to publication data
 * for internal views like SearchIndex without access control restrictions.
 * 
 * These endpoints are essentially copies of publication endpoints but designed
 * for internal federation access patterns.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class FederationController extends Controller
{
    /**
     * FederationController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param PublicationService $publicationService The publication service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Retrieve all publications from local and federated sources.
     *
     * This endpoint is designed for internal use by search interfaces and provides
     * the same functionality as the publications endpoint but without access restrictions
     * that might occur when switching between frontend contexts.
     * 
     * @return JSONResponse JSON response containing publications, pagination info, and optionally facets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publications(): JSONResponse
    {
        // Get all current query parameters
        $queryParams = $this->request->getParams();
        
        // Build base URL for pagination links
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $baseUrl = $protocol . '://' . $host . strtok($uri, '?');
        
        try {
            // Use the service method to get aggregated publications
            $responseData = $this->publicationService->getAggregatedPublications(
                $queryParams, 
                $this->request->getParams(), 
                $baseUrl
            );
            
            return new JSONResponse($responseData);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publications: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Retrieve a specific publication by its ID.
     *
     * This endpoint searches for a publication in local and federated catalogs
     * and is designed for internal use by search interfaces.
     *
     * @param  string $id The ID of the publication to retrieve
     * @return JSONResponse JSON response containing the requested publication
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publication(string $id): JSONResponse
    {
        try {
            // Use the service method to get the publication with federation support
            $result = $this->publicationService->getFederatedPublication($id, $this->request->getParams());
            
            return new JSONResponse($result['data'], $result['status']);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publication: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Retrieve objects that this publication references
     *
     * This method returns all objects that this publication uses/references.
     * A -> B means that A (This publication) references B (Another object).
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
    public function publicationUses(string $id): JSONResponse
    {
        try {
            // Use the service method to get the publication uses with federation support
            $result = $this->publicationService->getFederatedUses($id, $this->request->getParams());
            
            return new JSONResponse($result['data'], $result['status']);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publication uses: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Retrieve objects that use this publication
     *
     * This method returns all objects that reference (use) this publication.
     * B -> A means that B (Another object) references A (This publication).
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
    public function publicationUsed(string $id): JSONResponse
    {
        try {
            // Use the service method to get the publication used with federation support
            $result = $this->publicationService->getFederatedUsed($id, $this->request->getParams());
            
            return new JSONResponse($result['data'], $result['status']);
            
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publication used: ' . $e->getMessage()], 500);
        }
    }

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
    public function publicationAttachments(string $id): JSONResponse
    {
        return $this->publicationService->attachments(id: $id);
    }

    /**
     * Download all files of a publication as ZIP.
     *
     * @param  string $id Id of publication
     *
     * @return JSONResponse JSON response for download or error response.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publicationDownload(string $id): JSONResponse
    {
        return $this->publicationService->download(id: $id);
    }

} 