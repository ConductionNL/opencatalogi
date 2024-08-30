<?php

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Db\MetaDataMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Db\DoesNotExistException;

class MetaDataService
{
	private string $appName = 'opencatalogi';

	public function __construct(
		private readonly ObjectService $objectService,
		private readonly MetaDataMapper $metaDataMapper,
	)
	{
	}

	public function getOne(string $id, $config): array
	{
		if($config->hasKey($this->appName, 'mongoStorage') === false
			|| $config->getValueString($this->appName, 'mongoStorage') !== '1'
		) {
			try {
				return new JSONResponse($this->metaDataMapper->find(id: (int) $id));
			} catch (DoesNotExistException $exception) {
				return new JSONResponse(data: ['error' => 'Not found'], statusCode: 404);
			}
		}
		$dbConfig['base_uri'] = $config->getValueString(app: $this->appName, key: 'mongodbLocation');
		$dbConfig['headers']['api-key'] = $config->getValueString(app: $this->appName, key: 'mongodbKey');
		$dbConfig['mongodbCluster'] = $config->getValueString(app: $this->appName, key: 'mongodbCluster');

		$filters['_id'] = (string) $id;

		return $this->objectService->findObject(filters: $filters, config: $dbConfig);
	}
}
