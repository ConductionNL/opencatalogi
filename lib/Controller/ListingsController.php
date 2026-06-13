<?php
/**
 * OpenCatalogi Listings Controller.
 *
 * Controller for handling listing-related operations in the OpenCatalogi app.
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
 *
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-16
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-17
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-18
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-19
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-20
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-21
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-22
 */

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for handling Listing-related operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ListingsController extends Controller
{
    /**
     * Constructor for ListingsController.
     *
     * @param string             $appName          The name of the app
     * @param IRequest           $request          The request object
     * @param IAppConfig         $config           The app configuration
     * @param ContainerInterface $container        Server container for dependency injection
     * @param IAppManager        $appManager       App manager for checking installed apps
     * @param DirectoryService   $directoryService The directory service
     * @param IL10N              $l10n             Localization service
     * @param IUserSession       $userSession      The user session
     * @param LoggerInterface    $logger           PSR-3 logger
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly DirectoryService $directoryService,
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
        private readonly ?LoggerInterface $logger=null
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Resolve the Access-Control-Allow-Origin header value for the current request.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
     * Special value '*' (the default) means "any origin allowed" and emits a literal '*'
     * — the caller's Origin is NEVER echoed back unless it appears on the allowlist (#735).
     *
     * @return string The header value to use for Access-Control-Allow-Origin.
     */
    private function resolveAllowedOrigin(): string
    {
        $configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
        if ($configured === '' || $configured === '*') {
            return '*';
        }

        $allowlist = array_filter(
            array_map('trim', explode(',', $configured)),
            static fn(string $entry): bool => $entry !== ''
        );

        $callerOrigin = $this->request->getHeader('Origin');
        if ($callerOrigin === '') {
            $callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
        }

        if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
            return $callerOrigin;
        }

        return ($allowlist[0] ?? '*');

    }//end resolveAllowedOrigin()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/specs/cross-origin-api-access/spec.md
     */
    public function preflightedCors(): Response
    {
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', 'PUT, POST, GET, DELETE, PATCH');
        $response->addHeader('Access-Control-Max-Age', '1728000');
        $response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()

    /**
     * Retrieve a list of listings based on provided filters and parameters.
     *
     * @return JSONResponse JSON response containing the list of listings and total count
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-16
     */
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Retrieve all request parameters.
        $requestParams = $this->request->getParams();

        // Get listing schema and register from configuration.
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');

        // Build query for searchObjectsPaginated.
        $query = [];

        // Add metadata filters.
        if (empty($listingSchema) === false || empty($listingRegister) === false) {
            $query['@self'] = [];
            if (empty($listingSchema) === false) {
                $query['@self']['schema'] = $listingSchema;
            }

            if (empty($listingRegister) === false) {
                $query['@self']['register'] = $listingRegister;
            }
        }

        // Add any additional filters from request params.
        if (isset($requestParams['filters']) === true) {
            foreach ($requestParams['filters'] as $key => $value) {
                if (in_array($key, ['schema', 'register']) === false) {
                    $query[$key] = $value;
                }
            }
        }

        // Add pagination and other params.
        if (isset($requestParams['limit']) === true) {
            $query['_limit'] = (int) $requestParams['limit'];
        }

        if (isset($requestParams['offset']) === true) {
            $query['_offset'] = (int) $requestParams['offset'];
        }

        // Fetch listing objects using searchObjectsPaginated (handles pagination internally).
        $data = $this->getObjectService()->searchObjectsPaginated($query);

        // Return JSON response.
        return new JSONResponse($data);

    }//end index()

    /**
     * Retrieve a specific listing by its ID.
     *
     * @param string|integer $id The ID of the listing to retrieve
     *
     * @return JSONResponse JSON response containing the requested listing
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-17
     */
    public function show(string | int $id): JSONResponse
    {
        // Get listing schema and register from configuration.
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');

        // Fetch the listing object by its ID with register/schema context.
        $object = $this->getObjectService()->find($id, [], false, $listingRegister, $listingSchema);

        // Convert to array if it is an Entity.
        $data = $object;
        if ($object instanceof \OCP\AppFramework\Db\Entity) {
            $data = $object->jsonSerialize();
        }

        // Return the listing as a JSON response.
        return new JSONResponse($data);

    }//end show()

    /**
     * Create a new listing.
     *
     * @return JSONResponse The response containing the created listing object.
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-18
     */
    public function create(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Get all parameters from the request.
        $data = $this->request->getParams();

        // Remove internal framework fields.
        unset($data['id'], $data['_route']);

        // Get listing schema and register from configuration.
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');

        // Save the new listing object.
        $object = $this->getObjectService()->saveObject(
            object: $data,
            extend: [],
            register: $listingRegister,
            schema: $listingSchema
        );

        // Return the created object as a JSON response.
        return new JSONResponse($object);

    }//end create()

    /**
     * Update an existing listing.
     *
     * @param string|integer $id The ID of the listing to update.
     *
     * @return JSONResponse The response containing the updated listing object.
     * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-19
     */
    public function update(string | int $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Get all parameters from the request.
        $data = $this->request->getParams();

        // Remove internal framework fields.
        unset($data['_route']);

        // Get listing schema and register from configuration.
        $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
        $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');

        // Save the updated listing object with the id as UUID for update.
        $object = $this->getObjectService()->saveObject(
            object: $data,
            extend: [],
            register: $listingRegister,
            schema: $listingSchema,
            uuid: (string) $id
        );

        // Return the updated object as a JSON response.
        return new JSONResponse($object);

    }//end update()

    /**
     * Delete a listing.
     *
     * @param string|integer $id The ID of the listing to delete.
     *
     * @return JSONResponse The response indicating the result of the deletion.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|\OCP\DB\Exception
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-20
     */
    public function destroy(string | int $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Delete the listing object by its UUID.
        $result = $this->getObjectService()->deleteObject((string) $id);

        // Return the result as a JSON response.
        $statusCode = 404;
        if ($result === true) {
            $statusCode = 200;
        }

        return new JSONResponse(['success' => $result], $statusCode);

    }//end destroy()

    /**
     * Synchronize a specific directory or all directories.
     *
     * When an ID is provided, the corresponding listing is looked up and its
     * directory URL is synced. When no ID is provided, all known directories
     * are synced (equivalent to a cron sync).
     *
     * @param string|null $id The ID of the listing whose directory to synchronize (optional).
     *
     * @return JSONResponse The response containing synchronization results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-21
     */
    public function synchronise(?string $id=null): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            if ($id !== null) {
                // Look up the listing to get its directory URL.
                $listingRegister = $this->config->getValueString('opencatalogi', 'listing_register', '');
                $listingSchema   = $this->config->getValueString('opencatalogi', 'listing_schema', '');
                $object          = $this->getObjectService()->find($id, [], false, $listingRegister, $listingSchema);
                $objectData      = $object;
                if ($object instanceof \OCP\AppFramework\Db\Entity) {
                    $objectData = $object->jsonSerialize();
                }

                $listingData = $objectData['object'] ?? $objectData;

                $directoryUrl = $listingData['directory'] ?? null;
                if (empty($directoryUrl) === true) {
                    return new JSONResponse(
                        data: ['message' => $this->l10n->t('Listing has no directory URL configured')],
                        statusCode: 400
                    );
                }

                $result = $this->directoryService->syncDirectory($directoryUrl);
            }//end if

            if ($id === null) {
                // Sync all known directories.
                $result = $this->directoryService->doCronSync();
            }

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['message' => $this->l10n->t('Synchronization failed').': '.$e->getMessage()],
                statusCode: 500
            );
        }//end try

    }//end synchronise()

    /**
     * Add a new listing from a URL.
     *
     * Requires authentication and CSRF protection. Federation peer-registration must
     * not be anonymous — an unauthenticated caller could otherwise register a hostile
     * directory pointing to attacker-controlled URLs and chain it into the federation
     * SSRF path (SB1 / WF1, wave-12). Dropped @PublicPage and @NoCSRFRequired.
     *
     * @return JSONResponse The response indicating the result of adding the listing.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-22
     */
    public function add(): JSONResponse
    {
        // Get the URL parameter from the request.
        $url = $this->request->getParam('url');

        if (empty($url) === true) {
            return new JSONResponse(data: ['message' => $this->l10n->t('Property "url" is required')], statusCode: 400);
        }

        // Add the new listing by syncing the provided directory URL.
        try {
            $result = $this->directoryService->syncDirectory($url);
        } catch (\InvalidArgumentException $exception) {
            // Validation errors: echo the caller's own input back as a safe message.
            return new JSONResponse(
                data: [
                    'message' => $this->l10n->t('Invalid directory URL'),
                    'error'   => $exception->getMessage(),
                ],
                statusCode: 400
            );
        } catch (GuzzleException $exception) {
            // Network/HTTP error: log server-side, return generic 502 — do not reflect
            // upstream response body (SSRF oracle risk).
            $this->logger?->warning(
                '[ListingsController::add] Upstream directory fetch failed',
                ['error' => $exception->getMessage()]
            );
            return new JSONResponse(
                data: ['message' => $this->l10n->t('Failed to fetch directory data')],
                statusCode: 502
            );
        } catch (\Exception $exception) {
            // Unexpected error: log server-side with trace, return generic 500.
            $this->logger?->error(
                '[ListingsController::add] Directory sync failed',
                [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                data: ['message' => $this->l10n->t('Internal server error')],
                statusCode: 500
            );
        }//end try

        // Return the result as a JSON response.
        return new JSONResponse($result);

    }//end add()
}//end class
