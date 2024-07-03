<?php

namespace OCA\OpenCatalog\Controller;

use OCA\OpenCatalog\Service\ElasticSearchService;
use OCA\OpenCatalog\Service\SearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class SearchController extends Controller
{
    const TEST_ARRAY = [
        "d9e1467e-fc55-44c8-bf5c-bf139ac10eda" => [
            "id" => "d9e1467e-fc55-44c8-bf5c-bf139ac10eda",
            "name" => "Search one",
            "summary" => "summary for one"
        ],
        "e9d0131b-06c4-4d20-aa17-3b2aaad186d7" => [
            "id" => "e9d0131b-06c4-4d20-aa17-3b2aaad186d7",
            "name" => "Search two",
            "summary" => "summary for two"
        ]
    ];

    public function __construct($appName, IRequest $request, private readonly IAppConfig $config)
    {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(?string $getParameter)
    {
        // The TemplateResponse loads the 'main.php'
        // defined in our app's 'templates' folder.
        // We pass the $getParameter variable to the template
        // so that the value is accessible in the template.
        return new TemplateResponse(
            //Application::APP_ID,
            'opencatalog',
            'SearchIndex',
            []
        );
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(SearchService $searchService): JSONResponse
    {
		$elasticConfig['location'] = $this->config->getValueString(app: $this->appName, key: 'elasticLocation');
		$elasticConfig['key'] 	   = $this->config->getValueString(app: $this->appName, key: 'elasticKey');
		$elasticConfig['index']    = $this->config->getValueString(app: $this->appName, key: 'elasticIndex');

		$dbConfig['base_uri'] = $this->config->getValueString(app: $this->appName, key: 'mongodbLocation');
		$dbConfig['headers']['api-key'] = $this->config->getValueString(app: $this->appName, key: 'mongodbKey');
		$dbConfig['mongodbCluster'] = $this->config->getValueString(app: $this->appName, key: 'mongodbCluster');

		$filters = $this->request->getParams();

		unset($filters['_route']);

		$data = $searchService->search(parameters: $filters, elasticConfig: $elasticConfig, dbConfig: $dbConfig);

        return new JSONResponse(['results' => $data]);
    }
}
