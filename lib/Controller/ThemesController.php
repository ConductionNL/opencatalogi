<?php

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Db\ThemeMapper;
use OCA\OpenCatalogi\Service\ObjectService;
use OCA\OpenCatalogi\Service\SearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class ThemesController extends Controller
{
    public function __construct
	(
		$appName,
		IRequest $request,
		private readonly ThemeMapper $themeMapper,
		private readonly IAppConfig $config,
	)
    {
        parent::__construct($appName, $request);
    }

	/**
	 * This returns the template of the main app's page
	 * It adds some data to the template (app version)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function page(): TemplateResponse
	{
        return new TemplateResponse($this->appName, 'ThemesIndex', []);
	}

	/**
	 * The Index function.
	 *
	 * @param ObjectService $objectService The Object service.
	 * @param SearchService $searchService The Search service.
	 *
	 * @return JSONResponse The Response.
	 * @throws GuzzleException
	 */
	private function themesIndex(ObjectService $objectService, SearchService $searchService): JSONResponse
	{
		$filters = $this->request->getParams();
		unset($filters['_route']);
		$fieldsToSearch = ['title', 'description', 'summary'];

		if ($this->config->hasKey($this->appName, 'mongoStorage') === false
			|| $this->config->getValueString($this->appName, 'mongoStorage') !== '1'
		) {
			$searchParams = $searchService->createMySQLSearchParams(filters: $filters);
			$searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch:  $fieldsToSearch);
			$filters = $searchService->unsetSpecialQueryParams(filters: $filters);

			return new JSONResponse(['results' => $this->themeMapper->findAll(limit: null, offset: null, filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
		}

		$filters = $searchService->createMongoDBSearchFilter(filters: $filters, fieldsToSearch: $fieldsToSearch);
		$filters = $searchService->unsetSpecialQueryParams(filters: $filters);

		try {
			$dbConfig = [
				'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
				'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
				'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
			];

			$filters['_schema'] = 'theme';

			$result = $objectService->findObjects(filters: $filters, config: $dbConfig);

			return new JSONResponse(["results" => $result['documents']]);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Return (and search) all objects
	 *
	 * @CORS
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param ObjectService $objectService The Object service.
	 * @param SearchService $searchService The Search service.
	 *
	 * @return JSONResponse The Response.
	 * @throws GuzzleException
	 */
	public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
	{
		return $this->themesIndex($objectService, $searchService);
	}

	/**
	 * Return (and search) all objects
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param ObjectService $objectService The Object service.
	 * @param SearchService $searchService The Search service.
	 *
	 * @return JSONResponse The Response.
	 * @throws GuzzleException
	 */
	public function indexInternal(ObjectService $objectService, SearchService $searchService): JSONResponse
	{
		return $this->themesIndex($objectService, $searchService);
	}

	/**
	 * The Show function.
	 *
	 * @param string $id The id.
	 * @param ObjectService $objectService The Object Service.
	 *
	 * @return JSONResponse The response.
	 * @throws GuzzleException
	 */
	private function themesShow(string $id, ObjectService $objectService): JSONResponse
	{
		if ($this->config->hasKey($this->appName, 'mongoStorage') === false
			|| $this->config->getValueString($this->appName, 'mongoStorage') !== '1'
		) {
			try {
				return new JSONResponse($this->themeMapper->find(id: (int) $id));
			} catch (DoesNotExistException $exception) {
				return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
			}
		}

		try {
			$dbConfig = [
				'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
				'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
				'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
			];

			$filters['_id'] = (string) $id;

			$result = $objectService->findObject($filters, $dbConfig);

			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Read a single object
	 *
	 * @CORS
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $id The id.
	 * @param ObjectService $objectService The Object Service.
	 *
	 * @return JSONResponse The response.
	 * @throws GuzzleException
	 */
	public function show(string $id, ObjectService $objectService): JSONResponse
	{
		return $this->themesShow($id, $objectService);
	}

	/**
	 * Read a single object
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $id The id.
	 * @param ObjectService $objectService The Object Service.
	 *
	 * @return JSONResponse The response.
	 * @throws GuzzleException
	 */
	public function showInternal(string $id, ObjectService $objectService): JSONResponse
	{
		return $this->themesShow($id, $objectService);
	}


	/**
	 * Create an object
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function create(ObjectService $objectService): JSONResponse
	{

		$data = $this->request->getParams();

		foreach ($data as $key => $value) {
			if (str_starts_with($key, '_')) {
				unset($data[$key]);
			}
		}
		if ($this->config->hasKey($this->appName, 'mongoStorage') === false
			|| $this->config->getValueString($this->appName, 'mongoStorage') !== '1'
		) {
			return new JSONResponse($this->themeMapper->createFromArray(object: $data));
		}

		try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

			$data['_schema'] = 'theme';

			$returnData = $objectService->saveObject(
				data: $data,
				config: $dbConfig
			);

            return new JSONResponse($returnData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
	}

	/**
	 * Update an object
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function update(string $id, ObjectService $objectService): JSONResponse
	{
		$data = $this->request->getParams();

		foreach ($data as $key => $value) {
			if (str_starts_with($key, '_')) {
				unset($data[$key]);
			}
		}
		if (isset($data['id'])) {
			unset($data['id']);
		}

		if ($this->config->hasKey($this->appName, 'mongoStorage') === false
			|| $this->config->getValueString($this->appName, 'mongoStorage') !== '1'
		) {
			return new JSONResponse($this->themeMapper->updateFromArray(id: (int) $id, object: $data));
		}

        try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

            $filters['_id'] = (string) $id;
            $returnData = $objectService->updateObject($filters, $data, $dbConfig);

            return new JSONResponse($returnData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
	}

	/**
	 * Delate an object
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function destroy(string $id, ObjectService $objectService): JSONResponse
	{
		if ($this->config->hasKey($this->appName, 'mongoStorage') === false
			|| $this->config->getValueString($this->appName, 'mongoStorage') !== '1'
		) {
			$this->themeMapper->delete($this->themeMapper->find((int) $id));

			return new JSONResponse([]);
		}

        try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

            $filters['_id'] = (string) $id;
            $returnData = $objectService->deleteObject($filters, $dbConfig);

            return new JSONResponse($returnData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
