<?php

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class GlossaryController
 *
 * Controller for handling glossary-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class GlossaryController extends Controller
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
     * @var integer CORS max age
     */
    private int $corsMaxAge;


    /**
     * GlossaryController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param IAppConfig         $config             App configuration interface
     * @param ContainerInterface $container          Server container for dependency injection
     * @param IAppManager        $appManager         App manager for checking installed apps
     * @param string             $corsMethods        Allowed CORS methods
     * @param string             $corsAllowedHeaders Allowed CORS headers
     * @param integer            $corsMaxAge         CORS max age
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
        int $corsMaxAge = 1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;

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

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Get the schema and register configuration for glossary.
     *
     * @return array<string, string> Array containing schema and register configuration
     */
    private function getGlossaryConfiguration(): array
    {
        // Get the glossary schema and register from configuration
        $schema   = $this->config->getValueString($this->appName, 'glossary_schema', '');
        $register = $this->config->getValueString($this->appName, 'glossary_register', '');

        return [
            'schema'   => $schema,
            'register' => $register,
        ];

    }//end getGlossaryConfiguration()


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

    }//end preflightedCors()


    /**
     * Get all glossary terms - OPTIMIZED with searchObjectsPaginated.
     *
     * @return JSONResponse The JSON response containing the list of glossary terms
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        // Get glossary configuration from settings
        $glossaryConfig = $this->getGlossaryConfiguration();

        // Get query parameters from request
        $queryParams = $this->request->getParams();

        // Build search query
        $searchQuery = $queryParams;

        // Clean up unwanted parameters
        unset($searchQuery['id'], $searchQuery['_route']);

        // Add schema filter if configured - use proper OpenRegister syntax
        if (!empty($glossaryConfig['schema'])) {
            $searchQuery['@self']['schema'] = $glossaryConfig['schema'];
        }

        // Add register filter if configured - use proper OpenRegister syntax
        if (!empty($glossaryConfig['register'])) {
            $searchQuery['@self']['register'] = $glossaryConfig['register'];
        }

        // Use database source (SOLR index may not be available in all environments)
        $searchQuery['_source'] = 'database';

        // Use searchObjectsPaginated for better performance and pagination support
        // Set rbac=false, multi=false, published=true for public glossary access
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: false, _multitenancy: false, published: true);

        // Build paginated response structure
        $responseData = [
            'results' => ($result['results'] ?? []),
            'total'   => ($result['total'] ?? 0),
            'limit'   => ($result['limit'] ?? 20),
            'offset'  => ($result['offset'] ?? 0),
            'page'    => ($result['page'] ?? 1),
            'pages'   => ($result['pages'] ?? 1),
        ];

        // Add pagination links if present
        if (isset($result['next'])) {
            $responseData['next'] = $result['next'];
        }

        if (isset($result['prev'])) {
            $responseData['prev'] = $result['prev'];
        }

        // Add facets if present
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

        // Add CORS headers for public API access
        $response = new JSONResponse($responseData);
        $origin   = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end index()


    /**
     * Get a specific glossary term by its ID.
     *
     * @param string|integer $id The ID of the glossary term to retrieve
     *
     * @return JSONResponse The JSON response containing the glossary term details
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string|int $id): JSONResponse
    {
        // Use searchObjectsPaginated to find single glossary term with published=true filter
        $searchQuery = [
            '_ids'    => [$id],
            '_limit'  => 1,
            '_source' => 'database',
        ];
        $result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: false, _multitenancy: false, published: true);

        if (empty($result['results'])) {
            return new JSONResponse(['error' => 'Glossary term not found'], 404);
        }

        $glossaryTerm = $result['results'][0];

        $data = $glossaryTerm instanceof \OCP\AppFramework\Db\Entity ? $glossaryTerm->jsonSerialize() : $glossaryTerm;

        // Add CORS headers for public API access
        $response = new JSONResponse($data);
        $origin   = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

        return $response;

    }//end show()


}//end class
