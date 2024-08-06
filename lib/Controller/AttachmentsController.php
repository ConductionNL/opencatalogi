<?php

namespace OCA\OpenCatalogi\Controller;

use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Db\AttachmentMapper;
use OCA\OpenCatalogi\Service\ElasticSearchService;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenCatalogi\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

class AttachmentsController extends Controller
{

    public function __construct
	(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly AttachmentMapper $attachmentMapper,
		private readonly FileService $fileService
	)
    {
        parent::__construct($appName, $request);
		$this->fileService->setAppName($appName);
    }

	private function insertNestedObjects(array $object, ObjectService $objectService, array $config): array
	{
		foreach($object as $key => $value) {
			try {
				if(
					is_string(value: $value)
					&& $key !== 'id'
					&& Uuid::isValid(uuid: $value) === true
					&& $subObject = $objectService->findObject(filters: ['_id' => $value], config: $config)
				) {
					$object[$key] = $subObject;
				}
			} catch (GuzzleException $exception) {
				continue;
			}
		}

		return $object;
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
            $this->appName,
            'AttachmentsIndex',
            []
        );
    }

    /**
     * Taking it from a catalogue point of view is just adding a filter
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function catalog(string|int $id): TemplateResponse
    {
        // The TemplateResponse loads the 'main.php'
        // defined in our app's 'templates' folder.
        // We pass the $getParameter variable to the template
        // so that the value is accessible in the template.
        return new TemplateResponse(
            //Application::APP_ID,
            $this->appName,
            'AttachmentsIndex',
            []
        );
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(ObjectService $objectService): JSONResponse
    {
		if ($this->config->hasKey(app: $this->appName, key: 'mongoStorage') === false
			|| $this->config->getValueString(app: $this->appName, key: 'mongoStorage') !== '1'
		) {
			return new JSONResponse(['results' =>$this->attachmentMapper->findAll()]);
		}
		$dbConfig['base_uri'] = $this->config->getValueString(app: $this->appName, key: 'mongodbLocation');
		$dbConfig['headers']['api-key'] = $this->config->getValueString(app: $this->appName, key: 'mongodbKey');
		$dbConfig['mongodbCluster'] = $this->config->getValueString(app: $this->appName, key: 'mongodbCluster');

		$filters = $this->request->getParams();

		foreach($filters as $key => $value) {
			if(str_starts_with($key, '_')) {
				unset($filters[$key]);
			}
		}

		$filters['_schema'] = 'attachment';

		$result = $objectService->findObjects(filters: $filters, config: $dbConfig);

        $results = ["results" => $result['documents']];
        return new JSONResponse($results);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string|int $id, ObjectService $objectService): JSONResponse
    {
		if ($this->config->hasKey(app: $this->appName, key: 'mongoStorage') === false
			|| $this->config->getValueString(app: $this->appName, key: 'mongoStorage') !== '1'
		) {
			return new JSONResponse($this->attachmentMapper->find(id: (int) $id));
		}
		$dbConfig['base_uri'] = $this->config->getValueString(app: $this->appName, key: 'mongodbLocation');
		$dbConfig['headers']['api-key'] = $this->config->getValueString(app: $this->appName, key: 'mongodbKey');
		$dbConfig['mongodbCluster'] = $this->config->getValueString(app: $this->appName, key: 'mongodbCluster');

		$filters['_id'] = (string) $id;

		$result = $objectService->findObject(filters: $filters, config: $dbConfig);

        return new JSONResponse($result);
    }


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @throws GuzzleException In case the file upload to NextCloud fails.
	 */
    public function create(ObjectService $objectService, ElasticSearchService $elasticSearchService): JSONResponse
    {
		$data = $this->request->getParams();

		// Check if a file was uploaded
		$uploadedFile = $this->request->getUploadedFile(key: '_file');
		if (empty($uploadedFile) === true) {
			return new JSONResponse(data: ['error' => 'No file uploaded for key "_file"'], statusCode: 400);
		}

		// Check for upload errors
		if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return new JSONResponse(data: ['error' => 'File upload error: '.$uploadedFile['error']], statusCode: 400);
		}

		// Save the uploaded file
		$this->fileService->createFolder(folderPath: 'Attachments');
		$this->fileService->uploadFile(
			content: file_get_contents(filename: $uploadedFile['tmp_name']),
			filePath: 'Attachments/'.$uploadedFile['name']
		);

		// Update Attachment data
		$data['downloadUrl'] = $this->fileService->createShareLink(path: 'Attachments/'.$uploadedFile['name']);
		$data['type'] = $uploadedFile['type'];
		$data['size'] = $uploadedFile['size'];
		$explodedName = explode(separator: '.', string: $uploadedFile['name']);
		$data['title'] = $explodedName[0];
		$data['extension'] = end(array: $explodedName);

		// Remove fields we should never post
		unset($data['id']);
		foreach($data as $key => $value) {
			if(str_starts_with(haystack: $key, needle: '_')) {
				unset($data[$key]);
			}
		}

		if ($this->config->hasKey(app: $this->appName, key: 'mongoStorage') === false
			|| $this->config->getValueString(app: $this->appName, key: 'mongoStorage') !== '1'
		) {
			return new JSONResponse($this->attachmentMapper->createFromArray(object: $data));
		}

		$dbConfig['base_uri'] = $this->config->getValueString(app: $this->appName, key: 'mongodbLocation');
		$dbConfig['headers']['api-key'] = $this->config->getValueString(app: $this->appName, key: 'mongodbKey');
		$dbConfig['mongodbCluster'] = $this->config->getValueString(app: $this->appName, key: 'mongodbCluster');

		$data['_schema'] = 'attachment';

		$returnData = $objectService->saveObject(
			data: $data,
			config: $dbConfig
		);

        // get post from requests
        return new JSONResponse($returnData);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
	 * @throws GuzzleException In case updating the file in NextCloud fails.
     */
    public function update(string|int $id, ObjectService $objectService, ElasticSearchService $elasticSearchService): JSONResponse
    {
		$data = $this->request->getParams();

		// Todo: $uploadedFile is empty when doing a PUT...
		$uploadedFile = $this->request->getUploadedFile(key: '_file');

		// Save the uploaded file
		$this->fileService->createFolder(folderPath: 'Attachments');
		$this->fileService->uploadFile(
			content: file_get_contents(filename: $uploadedFile['tmp_name']),
			filePath: 'Attachments/'.$uploadedFile['name'],
			update: true
		);

		// Update Attachment data
		// Todo: when should we create a new share link?
//		$data['downloadUrl'] = $this->fileService->createShareLink(path: 'Attachments/'.$uploadedFile['name']);
		$data['type'] = $uploadedFile['type'];
		$data['size'] = $uploadedFile['size'];
		$explodedName = explode(separator: '.', string: $uploadedFile['name']);
		$data['title'] = $explodedName[0];
		$data['extension'] = end(array: $explodedName);

		// Remove fields we should never post
		unset($data['id']);
		foreach($data as $key => $value) {
			if(str_starts_with(haystack: $key, needle: '_')) {
				unset($data[$key]);
			}
		}

		if ($this->config->hasKey(app: $this->appName, key: 'mongoStorage') === false
			|| $this->config->getValueString(app: $this->appName, key: 'mongoStorage') !== '1'
		) {
			return new JSONResponse($this->attachmentMapper->updateFromArray(id: (int) $id, object: $data));
		}


		$dbConfig['base_uri'] = $this->config->getValueString(app: $this->appName, key: 'mongodbLocation');
		$dbConfig['headers']['api-key'] = $this->config->getValueString(app: $this->appName, key: 'mongodbKey');
		$dbConfig['mongodbCluster'] = $this->config->getValueString(app: $this->appName, key: 'mongodbCluster');


		$filters['_id'] = (string) $id;
		$returnData = $objectService->updateObject(
			filters: $filters,
			update: $data,
			config: $dbConfig
		);

		// get post from requests
		return new JSONResponse($returnData);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
	 * @throws GuzzleException In case deleting the file from NextCloud fails.
	 * @throws \OCP\DB\Exception In case deleting attachment from the NextCloud DB fails.
     */
    public function destroy(string|int $id, ObjectService $objectService, ElasticSearchService $elasticSearchService): JSONResponse
    {
		$attachment = $this->show(id: $id, objectService: $objectService)->getData();

		if ($this->config->hasKey(app: $this->appName, key: 'mongoStorage') === false
			|| $this->config->getValueString(app: $this->appName, key: 'mongoStorage') !== '1'
		) {
			$attachment = $attachment->jsonSerialize();

			// Todo: are we sure this is the best way to do this (how do we save the full path to this file in nextCloud)
			$this->fileService->deleteFile(filePath: 'Attachments/' . $attachment['title'] . '.' . $attachment['extension']);
			$this->attachmentMapper->delete(entity: $this->attachmentMapper->find(id: (int) $id));

			return new JSONResponse([]);
		}

		$dbConfig['base_uri'] = $this->config->getValueString(app: $this->appName, key: 'mongodbLocation');
		$dbConfig['headers']['api-key'] = $this->config->getValueString(app: $this->appName, key: 'mongodbKey');
		$dbConfig['mongodbCluster'] = $this->config->getValueString(app: $this->appName, key: 'mongodbCluster');

		// Todo: are we sure this is the best way to do this (how do we save the full path to this file in nextCloud)
		$this->fileService->deleteFile(filePath: 'Attachments/' . $attachment['title'] . '.' . $attachment['extension']);

		$filters['_id'] = (string) $id;
		$returnData = $objectService->deleteObject(
			filters: $filters,
			config: $dbConfig
		);

		// get post from requests
		return new JSONResponse($returnData);
    }
}
