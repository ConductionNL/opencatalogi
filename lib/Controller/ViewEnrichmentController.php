<?php

/**
 * OpenCatalogi View Enrichment Controller.
 *
 * Exposes a public GET endpoint that returns gebruiksobjecten enriched with deelnames data
 * for a given organization. Clients (e.g. softwarecatalog GEMMA view) pass
 * include_gebruik and include_deelnames_gebruik flags to control which data sets are
 * returned. The response carries CORS headers for cross-origin frontend consumers.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\ViewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Controller for the view-enrichment API endpoint.
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-3
 */
class ViewEnrichmentController extends Controller
{

    /**
     * Allowed CORS methods.
     *
     * @var string
     */
    private string $corsMethods;

    /**
     * Allowed CORS headers.
     *
     * @var string
     */
    private string $corsAllowedHeaders;

    /**
     * CORS max age.
     *
     * @var integer
     */
    private int $corsMaxAge;

    /**
     * ViewEnrichmentController constructor.
     *
     * @param string      $appName            The app name.
     * @param IRequest    $request            The request object.
     * @param ViewService $viewService        The view service.
     * @param string      $corsMethods        Allowed CORS methods.
     * @param string      $corsAllowedHeaders Allowed CORS headers.
     * @param integer     $corsMaxAge         CORS max age.
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-3
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly ViewService $viewService,
        string $corsMethods='PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders='Authorization, Content-Type, Accept',
        int $corsMaxAge=1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;

    }//end __construct()

    /**
     * Add standard CORS headers to a response.
     *
     * @param JSONResponse $response The response to decorate.
     *
     * @return JSONResponse The same response with CORS headers added.
     */
    private function withCors(JSONResponse $response): JSONResponse
    {
        $origin = $this->request->server['HTTP_ORIGIN'] ?? '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end withCors()

    /**
     * Handle CORS preflight OPTIONS requests.
     *
     * @return Response The CORS preflight response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): Response
    {
        $origin = $this->request->getHeader('Origin');
        if ($origin === '') {
            $origin = '*';
        }

        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()

    /**
     * Return enriched view data for an organization, including deelnames gebruik when requested.
     *
     * Query parameters:
     *   - organization_id (string, required)  UUID of the organization.
     *   - include_gebruik (bool, default false)  Include owned gebruiksobjecten.
     *   - include_deelnames_gebruik (bool, default false)  Include deelnames gebruiksobjecten.
     *
     * Response shape:
     * {
     *   "organization_id": "...",
     *   "owned": [...],
     *   "deelnames": [...],
     *   "warnings": [...]
     * }
     *
     * @return JSONResponse The enriched view data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-3
     */
    public function index(): JSONResponse
    {
        $params = $this->request->getParams();

        $organizationId = trim(string: (string) ($params['organization_id'] ?? ''));

        if ($organizationId === '') {
            return $this->withCors(
                    new JSONResponse(
                ['error' => 'organization_id is required'],
                400
            )
                    );
        }

        $includeGebruik   = $this->parseBoolParam(params: $params, key: 'include_gebruik');
        $includeDeelnames = $this->parseBoolParam(params: $params, key: 'include_deelnames_gebruik');

        $result = $this->viewService->getGebruikForOrganization(
            organizationId: $organizationId,
            includeGebruik: $includeGebruik,
            includeDeelnames: $includeDeelnames
        );

        $response = new JSONResponse(
                [
                    'organization_id' => $organizationId,
                    'owned'           => $result['owned'],
                    'deelnames'       => $result['deelnames'],
                    'warnings'        => $result['warnings'],
                ]
                );

        return $this->withCors(response: $response);

    }//end index()

    /**
     * Parse a boolean-like query parameter.
     *
     * Accepts: true/false (bool), "true"/"false" (string), "1"/"0" (string).
     *
     * @param array<string, mixed> $params Query parameters array.
     * @param string               $key    Parameter name to read.
     *
     * @return bool Parsed boolean value; false when key is absent.
     */
    private function parseBoolParam(array $params, string $key): bool
    {
        if (isset($params[$key]) === false) {
            return false;
        }

        $value = $params[$key];

        if (is_bool(value: $value) === true) {
            return $value;
        }

        return in_array(
            needle: strtolower(string: (string) $value),
            haystack: ['true', '1', 'yes'],
            strict: true
        );

    }//end parseBoolParam()
}//end class
