<?php
/**
 * OpenCatalogi Search Controller.
 *
 * Controller for handling search-related operations in the OpenCatalogi app.
 * `index()` is the public, anonymous-reachable full-text search endpoint (WOO-506);
 * the remaining methods are internal/admin-use endpoints for testing purposes.
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

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for handling internal search-related operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SearchController extends Controller
{
    /**
     * SearchController constructor.
     *
     * @param string                  $appName            The name of the app.
     * @param IRequest                $request            The request object.
     * @param PublicationService      $publicationService The publication service.
     * @param IUserSession            $userSession        The user session.
     * @param IL10N                   $l10n               The localization service.
     * @param PublicationQueryService $queryService       Public search assembly helper (WOO-506).
     * @param ContainerInterface      $container          DI container, to resolve OpenRegister's ObjectService.
     * @param IAppManager             $appManager         The app manager.
     * @param LoggerInterface         $logger             PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
        private readonly PublicationQueryService $queryService,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * @return object The OpenRegister ObjectService.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws RuntimeException When OpenRegister is not installed.
     *
     * @spec exclude Lazy dependency-injection accessor; pure framework plumbing.
     */
    private function getObjectService(): object
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Public, RBAC-filtered full-text search across publications and documents (WOO-506).
     *
     * Absorbs the previous admin-only search into a public, anonymous-reachable
     * endpoint. Delegates entirely to OR's zoeken-filteren via
     * {@see PublicationQueryService::assemblePublicSearchResults()} (SCH-OR-003,
     * SCH-PFTS-001, SCH-PFTS-002, SCH-PFTS-006, SCH-PFTS-007) — this controller
     * performs no bespoke query building or scoring of its own.
     *
     * Dual-path (design.md "Dual-path design — pending Ruben's decision"): this
     * endpoint currently ships Path B — metadata-only document matching. When Ruben
     * confirms Path A (proposal.md "Pending decisions"), document content indexing is
     * wired via OR's TextExtractionService + FileHandler + Solr-pipeline
     * (SCH-PFTS-006); OpenCatalogi MUST NOT add a parallel extraction pipeline here.
     *
     * @return JSONResponse JSON response containing the mixed publication/document result envelope.
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/add-public-fulltext-search/tasks.md#task-3
     */
    public function index(): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();

            $result = $this->queryService->assemblePublicSearchResults(
                queryParams: $this->request->getParams(),
                objectService: $objectService
            );

            return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
        } catch (\Exception $e) {
            // Public endpoint — log exception details server-side only and return a
            // generic error body to the caller; never leak raw $e->getMessage().
            $this->logger->error(
                '[SearchController::index] Failed to execute public search',
                ['error' => $e->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => $this->l10n->t('Internal server error')],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end index()

    /**
     * Retrieve a specific publication by its ID.
     *
     * This is an internal endpoint for testing and administrative purposes.
     *
     * @param string $id The ID of the publication to retrieve.
     *
     * @return JSONResponse JSON response containing the requested publication.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-search/tasks.md#task-5
     */
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return $this->publicationService->show(id: $id);

    }//end show()

    /**
     * Retrieve attachments/files of a publication.
     *
     * This is an internal endpoint for testing and administrative purposes.
     *
     * @param string $id Id of publication.
     *
     * @return JSONResponse JSON response containing the requested attachments.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-search/tasks.md#task-6
     */
    public function attachments(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return $this->publicationService->attachments(id: $id);

    }//end attachments()

    /**
     * Download files of a publication.
     *
     * This is an internal endpoint for testing and administrative purposes.
     *
     * @param string $id Id of publication.
     *
     * @return DataDownloadResponse|JSONResponse The download response.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-search/tasks.md#task-7
     */
    public function download(string $id): DataDownloadResponse|JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return $this->publicationService->download(id: $id);

    }//end download()

    /**
     * Retrieves all objects that this publication references.
     *
     * This method returns all objects that this publication uses/references.
     * A -> B means that A (This publication) references B (Another object).
     *
     * @param string $id The ID of the publication to retrieve relations for.
     *
     * @return JSONResponse A JSON response containing the related objects.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-search/tasks.md#task-8
     */
    public function uses(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return $this->publicationService->uses(id: $id);

    }//end uses()

    /**
     * Retrieves all objects that use this publication.
     *
     * This method returns all objects that reference (use) this publication.
     * B -> A means that B (Another object) references A (This publication).
     *
     * @param string $id The ID of the publication to retrieve uses for.
     *
     * @return JSONResponse A JSON response containing the referenced objects.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-search/tasks.md#task-9
     */
    public function used(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return $this->publicationService->used(id: $id);

    }//end used()
}//end class
