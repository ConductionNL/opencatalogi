<?php
/**
 * OpenCatalogi Search Controller.
 *
 * Controller for handling internal search-related operations in the OpenCatalogi app.
 * This controller is designed for internal/admin use and testing purposes.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Http\DataDownloadResponse;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for handling internal search-related operations.
 */
class SearchController extends Controller
{
    /**
     * SearchController constructor.
     *
     * @param string             $appName            The name of the app.
     * @param IRequest           $request            The request object.
     * @param PublicationService $publicationService The publication service.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly ?PublicationQueryService $queryService=null,
        private readonly ?ContainerInterface $container=null,
        private readonly ?LoggerInterface $logger=null,
        private readonly ?IL10N $l10n=null
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Search endpoint. Two behaviours behind the same route:
     *
     *  - **WOO-506 / WOO-517 public full-text search** (fires when `_search` is present in
     *    the query). Delegates to {@see PublicationQueryService::assemblePublicSearchResults()},
     *    returns the flat mixed envelope with `@self.schema` discriminator. Respects the
     *    opt-in `_content=true` for document body-text search (WOO-517 / OR PR #473).
     *  - **Pre-WOO-506 internal listing** (fires when `_search` is absent). Preserves the
     *    original main behaviour: delegates to `PublicationService::index($catalogId)` and
     *    lists publications, optionally scoped by catalog. This branch is a defensive
     *    backward-compat guard for any admin/testing tool that still hits `/api/search`
     *    without a search term.
     *
     * @param string|null $catalogId Optional ID of a specific catalog to filter by
     *                               (only honoured by the legacy internal listing branch).
     *
     * @return JSONResponse JSON response — search-envelope or publication list.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @BruteForceProtection(action=publicSearch)
     */
    public function index(?string $catalogId=null): JSONResponse
    {
        // WOO-506 public FTS gate: opt-in when `_search` is supplied, OR when the caller
        // wants facets (`_facetable=true` / `_facets[...]`) which are only usefully served
        // by the FTS-envelope path. Absent → pre-WOO-506 internal-listing behaviour is
        // preserved byte-for-byte below.
        $searchTerm     = $this->request->getParam('_search');
        $hasSearch      = ($searchTerm !== null && trim((string) $searchTerm) !== '');
        $wantsFacetable = filter_var($this->request->getParam('_facetable', false), FILTER_VALIDATE_BOOLEAN) === true;
        $wantsFacets    = is_array($this->request->getParam('_facets')) === true;
        if (($hasSearch === true || $wantsFacetable === true || $wantsFacets === true) && $this->queryService !== null) {
            try {
                $objectService = $this->getObjectService();
                $result = $this->queryService->assemblePublicSearchResults(
                    queryParams: $this->request->getParams(),
                    objectService: $objectService
                );
                return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
            } catch (RuntimeException $e) {
                if ($this->logger !== null) {
                    $this->logger->warning(
                        '[SearchController::index] OpenRegister not installed — public search unavailable',
                        ['error' => $e->getMessage()]
                    );
                }
                $errorMsg = $this->l10n !== null ? $this->l10n->t('Search backend is not available.') : 'Search backend is not available.';
                return new JSONResponse(
                    data: ['error' => $errorMsg],
                    statusCode: Http::STATUS_SERVICE_UNAVAILABLE
                );
            } catch (\Exception $e) {
                if ($this->logger !== null) {
                    $this->logger->error(
                        '[SearchController::index] Failed to execute public search',
                        ['error' => $e->getMessage()]
                    );
                }
                $errorMsg = $this->l10n !== null ? $this->l10n->t('Internal server error') : 'Internal server error';
                return new JSONResponse(
                    data: ['error' => $errorMsg],
                    statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }
        }

        // Legacy pre-WOO-506 path: preserved unchanged.
        return $this->publicationService->index($catalogId);

    }//end index()

    /**
     * Resolve OpenRegister's ObjectService via the DI container. Kept as a method
     * (not typehinted constructor arg) because a live openregister app may or may
     * not be installed on any given deployment — the SearchController is loaded
     * regardless, and the ObjectService resolve is only attempted on the FTS path.
     *
     * @return object The OpenRegister ObjectService instance.
     *
     * @throws RuntimeException When OpenRegister is not installed / DI cannot resolve it.
     */
    private function getObjectService(): object
    {
        if ($this->container === null) {
            throw new RuntimeException('Container not available; SearchController FTS branch inactive.');
        }
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService not available: '.$e->getMessage(), 0, $e);
        }
    }//end getObjectService()

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
     */
    public function show(string $id): JSONResponse
    {
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
     */
    public function attachments(string $id): JSONResponse
    {
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
     */
    public function download(string $id): DataDownloadResponse|JSONResponse
    {
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
     */
    public function uses(string $id): JSONResponse
    {
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
     */
    public function used(string $id): JSONResponse
    {
        return $this->publicationService->used(id: $id);

    }//end used()
}//end class
