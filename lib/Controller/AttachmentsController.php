<?php

namespace OCA\OpenCatalogi\Controller;

ini_set('memory_limit', '2048M');

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Db\AttachmentMapper;
use OCA\OpenCatalogi\Service\ElasticSearchService;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenCatalogi\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Class AttachmentsController
 *
 * This controller handles CRUD operations for attachments in the OpenCatalogi app.
 */
class AttachmentsController extends Controller
{
    /**
     * AttachmentsController constructor.
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration
     * @param AttachmentMapper $attachmentMapper The attachment mapper
     * @param FileService $fileService The file service
     * @param IUserSession $userSession The user session
     * @param ObjectService $objectService The object service
     * @param IURLGenerator $urlGenerator The URL generator
     */
    public function __construct
	(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly AttachmentMapper $attachmentMapper,
		private readonly FileService $fileService,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly IURLGenerator $urlGenerator
	)
    {
        parent::__construct($appName, $request);
    }

    /**
     * Retrieve a list of attachments based on provided filters and parameters.
     *
     * @param ObjectService $objectService Service to handle object operations
	 *
     * @return JSONResponse JSON response containing the list of attachments and total count
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(ObjectService $objectService): JSONResponse
    {
        // Retrieve all request parameters
        $requestParams = $this->request->getParams();

        // Fetch attachment objects based on filters and order
        $data = $this->objectService->getResultArrayForRequest('attachment', $requestParams);

        // Return JSON response
        return new JSONResponse($data);
    }

    /**
     * Retrieve a specific attachment by its ID.
     *
     * @param string|int $id The ID of the attachment to retrieve
     * @param ObjectService $objectService Service to handle object operations
	 *
     * @return JSONResponse JSON response containing the requested attachment
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string|int $id, ObjectService $objectService): JSONResponse
    {
        // Fetch the attachment object by its ID
        $object = $this->objectService->getObject('attachment', $id);

        // Return the attachment as a JSON response
        return new JSONResponse($object);
    }

	/**
	 * Create a new attachment.
	 *
	 * @param ObjectService $objectService The service to handle object operations.
	 *
	 * @return JSONResponse The response containing the created attachment object.
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 * @throws Exception
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
    public function create(ObjectService $objectService): JSONResponse
    {
        // Get all parameters from the request.
        $data = $this->request->getParams();

        // Remove the 'id' field if it exists, as we're creating a new object.
        unset($data['id']);

		if (empty($data['downloadUrl']) === true) {
			// Check if a _file is present in the request and creates a NextCloud file if so, adding its info to the data array.
			$data = $this->fileService->handleFile(request: $this->request, data: $data);

			// In case of invalid input or 'internal error' when creating the file.
			if ($data instanceof JSONResponse) {
				return $data;
			}
		}

        // Save the new attachment object.
        $object = $this->objectService->saveObject('attachment', $data);

        // If object is a class change it to array
        if (is_object($object) === true) {
            $object = $object->jsonSerialize();
        }

        // If we do not have an uri, we need to generate one
        if (isset($object['uri']) === false) {
            $object['uri'] = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('openCatalogi.attachments.show', ['id' => $object['id']]));
            $object = $this->objectService->saveObject('attachment', $object);
        }

        // Return the created object as a JSON response.
        return new JSONResponse($object);
    }

    /**
     * Update an existing attachment.
     *
     * @param string|int $id The ID of the attachment to update.
     * @param ObjectService $objectService The service to handle object operations.
	 *
     * @return JSONResponse The response containing the updated attachment object.
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(string|int $id, ObjectService $objectService): JSONResponse
    {
        // Get all parameters from the request
        $data = $this->request->getParams();

        // Ensure the ID in the data matches the ID in the URL
        $data['id'] = $id;

        // If we do not have an uri, we need to generate one
        $data['uri'] = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('openCatalogi.attachments.show', ['id' => $data['id']]));

        // Save the updated attachment object
        $object = $this->objectService->saveObject('attachment', $data);

        // Return the updated object as a JSON response
        return new JSONResponse($object);
    }

	/**
	 * Delete an attachment.
	 *
	 * @param string|int $id The ID of the attachment to delete.
	 * @param ObjectService $objectService The service to handle object operations.
	 *
	 * @return JSONResponse The response indicating the result of the deletion.
	 * @throws DoesNotExistException|MultipleObjectsReturnedException getObject()
	 * @throws Exception deleteFile()
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface getObject() & deleteObject()
	 * @throws \OCP\DB\Exception deleteObject()
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
    public function destroy(string|int $id, ObjectService $objectService): JSONResponse
    {
		try {
			$attachment = $this->objectService->getObject('attachment', $id);

			$filePath = explode(separator: '/', string: $attachment['reference']);
			array_shift(array: $filePath);
			$filePath = implode(separator: '/', array: $filePath);
			$fileResult = $this->fileService->deleteFile(filePath: $filePath);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface) {
			$fileResult = false;
		}

        // Delete the attachment object
        $attachmentResult = $this->objectService->deleteObject('attachment', $id);

        // Return the result as a JSON response
		return new JSONResponse(
			data: ['success' => ['attachment' => $attachmentResult, 'NextCloud File' => $fileResult]],
			statusCode: $attachmentResult === true ? '200' : '404'
		);
    }
}
