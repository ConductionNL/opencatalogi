<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Db\CatalogMapper;
use OCA\OpenCatalogi\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class CatalogiController extends Controller
{
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
		private readonly CatalogMapper $catalogMapper
    )
    {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(?string $getParameter): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'CatalogiIndex', []);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(ObjectService $objectService): JSONResponse
    {
		if(true) {
			return new JSONResponse($this->catalogMapper->findAll());
		}
        try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

            $filters = $this->request->getParams();

            foreach ($filters as $key => $value) {
                if (str_starts_with($key, '_')) {
                    unset($filters[$key]);
                }
            }

            $filters['_schema'] = 'catalog';

            $result = $objectService->findObjects($filters, $dbConfig);

            return new JSONResponse(["results" => $result['documents']]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string $id, ObjectService $objectService): JSONResponse
    {
		if(true) {
			return new JSONResponse($this->catalogMapper->find(id: $id));
		}

        try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

            $filters['_id'] = $id;

            $result = $objectService->findObject($filters, $dbConfig);

            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(ObjectService $objectService): JSONResponse
    {
		$data = $this->request->getParams();

		foreach ($data as $key => $value) {
			if (str_starts_with($key, '_')) {
				unset($data[$key]);
			}
		}
		if(true) {
			return new JSONResponse($this->catalogMapper->createFromArray(object: $data));
		}

        try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

            $data['_schema'] = 'catalog';

            $returnData = $objectService->saveObject($data, $dbConfig);

            return new JSONResponse($returnData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
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

		if(true) {
			return new JSONResponse($this->catalogMapper->updateFromArray(id: $id, object: $data));
		}

        try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

            $filters['_id'] = $id;
            $returnData = $objectService->updateObject($filters, $data, $dbConfig);

            return new JSONResponse($returnData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $id, ObjectService $objectService): JSONResponse
    {
		if(true) {
			$this->catalogMapper->delete($this->catalogMapper->find($id));

			return new JSONResponse([]);
		}

        try {
            $dbConfig = [
                'base_uri' => $this->config->getValueString($this->appName, 'mongodbLocation'),
                'headers' => ['api-key' => $this->config->getValueString($this->appName, 'mongodbKey')],
                'mongodbCluster' => $this->config->getValueString($this->appName, 'mongodbCluster')
            ];

            $filters['_id'] = $id;
            $returnData = $objectService->deleteObject($filters, $dbConfig);

            return new JSONResponse($returnData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
