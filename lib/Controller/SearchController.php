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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

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
        private readonly PublicationService $publicationService
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Retrieve a list of publications based on all available catalogs.
     *
     * This is an internal endpoint for testing and administrative purposes.
     *
     * @param string|null $catalogId Optional ID of a specific catalog to filter by.
     *
     * @return JSONResponse JSON response containing the list of publications.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(?string $catalogId=null): JSONResponse
    {
        return $this->publicationService->index($catalogId);

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
