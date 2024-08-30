<?php

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Service\ObjectService;
use OCA\OpenCatalogi\Service\MetaDataService;
use OCA\OpenCatalogi\Db\CatalogMapper;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\AppFramework\Http\JSONResponse;
use JsonSchema\Validator;
use JsonSchema\Constraints\Constraint;

class ValidationService
{
	/**
	 * @var string The name of the application.
	 */
	private string $appName;

	/**
	 * @var array The current MongoDB Config.
	 */
	private array $mongodbConfig;

	/**
	 * @param IAppConfig    $config		   The application config
	 * @param CatalogMapper $catalogMapper The catalog mapper.
	 * @param ObjectService $objectService The object service.
	 */
	public function __construct(
		private readonly IAppConfig    $config,
		private readonly CatalogMapper $catalogMapper,
		private readonly ObjectService $objectService,
		private readonly MetaDataService $metaDataService,
	){
		$this->appName = 'opencatalogi';

		$this->mongodbConfig = [
			'base_uri' => $this->config->getValueString(app: $this->appName, key: 'mongodbLocation'),
			'headers' => ['api-key' => $this->config->getValueString(app: $this->appName, key: 'mongodbKey')],
			'mongodbCluster' => $this->config->getValueString(app: $this->appName, key:'mongodbCluster')
		];

	}

	/**
	 * @return array The mongodb config.
	 */
	public function getMongodbConfig(): array
	{
		return $this->mongodbConfig;
	}

	/**
	 * Fetches a catalog from either the local database or mongodb
	 *
	 * @param  string $id The id of the catalog to be fetched.
	 * @return array      The JSON Serialised catalog.
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function getCatalog (string $id): array
	{
		if ($this->config->hasKey(app: $this->appName, key: 'mongoStorage') !== false
			|| $this->config->getValueString(app: $this->appName, key: 'mongoStorage') === '1'
		) {
			$filter = ['id' => $id, '_schema' => 'catalog'];

            try {
                return $this->objectService->findObject(filters: $filter, config: $this->getMongodbConfig());
            } catch (OCSNotFoundException $exception) {
			    throw new OCSNotFoundException(message: 'Catalog not found for id: ' . $id);
            }
		}

		return $this->catalogMapper->find(id: $id)->jsonSerialize();
	}

	/**
	 * Validates a publication against the rules set for the publication.
	 *
	 * @param  array $publication The publication to be validated.
	 * @return array 			  The publication after it has been validated.
	 *
	 * @throws OCSBadRequestException Thrown if the object does not validate
	 */
	public function validatePublication(array $publication): array
	{
        $requiredFields = ['catalogi', 'metaData'];
        foreach ($requiredFields as $field) {
            if (isset($publication[$field]) === false) {
                throw new OCSBadRequestException(message: $field . ' is required but not given.');
            }
        }

		$catalogId  = $publication['catalogi'];
		$metadata   = $publication['metaData'];

        try {
		    $catalog = $this->getCatalog($catalogId);
        } catch (OCSNotFoundException $exception) {
            throw new OCSNotFoundException(message: $exception->getMessage());
        }
//		var_dump($catalog['metadata'], $metadata, in_array(needle: $metadata, haystack: $catalog['metadata']));

		if(in_array(needle: $metadata, haystack: $catalog['metadata']) === false) {
			throw new OCSBadRequestException(message: 'Given metadata object not present in catalog');
		}

//		var_dump($publication);

		return $publication;
	}

	/**
	 * Validates a publication against the linked metadata.
	 *
	 * @param  array $publication The publication to be validated.
     * 
     * @return array|JSONResponse
	 */
	public function validateDataAgainstMetaData(array $publication): array
	{
        if (isset($publication['schema']) === false) {
            return new JSONResponse(['message' => 'Missing required field: schema'], 404);
        }

        // Fetch metadata
        $metaData = json_decode(file_get_contents($publication['schema']));

        // Validate
        $validator = new Validator;
        $validator->validate($publication['data'], $metaData, Constraint::CHECK_MODE_APPLY_DEFAULTS);

        // Always reset errors and set valid as true
        $publication['errors'] = [];
        $publication['valid'] = true;

        // If invalid set invalid and add errors
        if (!$validator->isValid()) {
            $publication['valid'] = false;
            foreach ($validator->getErrors() as $error) {
                $publication['errors'][] = sprintf("[%s] %s\n", $error['property'], $error['message']);
            }
        }

		return $publication;
	}

}
