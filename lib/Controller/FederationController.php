<?php
/**
 * OpenCatalogi Federation Controller.
 *
 * Controller for handling federation endpoints that provide access to publication data
 * for internal views like SearchIndex without access control restrictions.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Http\DataDownloadResponse;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for handling federation endpoints.
 */
class FederationController extends Controller
{
    /**
     * FederationController constructor.
     *
     * @param string             $appName            The name of the app.
     * @param IRequest           $request            The request object.
     * @param PublicationService $publicationService The publication service.
     * @param IL10N              $l10n               The localization service.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly IL10N $l10n
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Retrieve all publications from local and federated sources.
     *
     * @return JSONResponse JSON response containing publications.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function publications(): JSONResponse
    {
        // Get all current query parameters.
        $queryParams = $this->request->getParams();

        // Build base URL for pagination links.
        $protocol = 'http';
        if (empty($_SERVER['HTTPS']) === false && $_SERVER['HTTPS'] !== 'off') {
            $protocol = 'https';
        }

        $host    = ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $uri     = ($_SERVER['REQUEST_URI'] ?? '/');
        $baseUrl = $protocol.'://'.$host.strtok($uri, '?');

        try {
            // Use the service method to get aggregated publications.
            $responseData = $this->publicationService->getAggregatedPublications(
                queryParams: $queryParams,
                requestParams: $this->request->getParams(),
                baseUrl: $baseUrl
            );

            return new JSONResponse($responseData);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Failed to retrieve publications').': '.$e->getMessage()],
                statusCode: 500
            );
        }

    }//end publications()

    /**
     * Retrieve a specific publication by its ID.
     *
     * @param string $id The ID of the publication to retrieve.
     *
     * @return JSONResponse JSON response containing the requested publication.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publication(string $id): JSONResponse
    {
        try {
            // Use the service method to get the publication with federation support.
            $result = $this->publicationService->getFederatedPublication($id, $this->request->getParams());

            return new JSONResponse($result['data'], $result['status']);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Failed to retrieve publication').': '.$e->getMessage()],
                statusCode: 500
            );
        }

    }//end publication()

    /**
     * Retrieve objects that this publication references.
     *
     * This method returns all objects that this publication uses/references.
     * When aggregation is enabled, it also searches federated catalogs.
     *
     * @param string $id The ID of the publication to retrieve relations for.
     *
     * @return JSONResponse A JSON response containing the related objects.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publicationUses(string $id): JSONResponse
    {
        try {
            // Use the service method to get the publication uses with federation support.
            $result = $this->publicationService->getFederatedUses($id, $this->request->getParams());

            return new JSONResponse($result['data'], $result['status']);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Failed to retrieve publication uses').': '.$e->getMessage()],
                statusCode: 500
            );
        }

    }//end publicationUses()

    /**
     * Retrieve objects that use this publication.
     *
     * This method returns all objects that reference (use) this publication.
     * When aggregation is enabled, it also searches federated catalogs.
     *
     * @param string $id The ID of the publication to retrieve uses for.
     *
     * @return JSONResponse A JSON response containing the referenced objects.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publicationUsed(string $id): JSONResponse
    {
        try {
            // Use the service method to get the publication used with federation support.
            $result = $this->publicationService->getFederatedUsed($id, $this->request->getParams());

            return new JSONResponse($result['data'], $result['status']);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Failed to retrieve publication used').': '.$e->getMessage()],
                statusCode: 500
            );
        }

    }//end publicationUsed()

    /**
     * Retrieve attachments/files of a publication.
     *
     * @param string $id Id of publication.
     *
     * @return JSONResponse JSON response containing the requested attachments/files.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publicationAttachments(string $id): JSONResponse
    {
        return $this->publicationService->attachments(id: $id);

    }//end publicationAttachments()

    /**
     * Download all files of a publication as ZIP.
     *
     * @param string $id Id of publication.
     *
     * @return DataDownloadResponse|JSONResponse JSON response for download or error response.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function publicationDownload(string $id): DataDownloadResponse|JSONResponse
    {
        return $this->publicationService->download(id: $id);

    }//end publicationDownload()
}//end class
