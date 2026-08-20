<?php

/**
 * Service for managing download-related operations.
 *
 * Renders the publication metadata PDF that is piped into the download ZIP and
 * enumerates a publication's attachments. Per DWN-OR-003 the rendered PDF is an
 * on-demand artefact — it is never saved to Nextcloud user storage by this service.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/download-service/spec.md
 * @spec openspec/specs/download-service/spec.md
 * @spec openspec/specs/download-service/spec.md
 * @spec openspec/specs/download-service/spec.md
 * @spec openspec/specs/download-service/spec.md
 * @spec openspec/specs/download-service/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use Exception;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Service for managing download-related operations.
 *
 * Renders the publication metadata PDF that is piped into the download ZIP and
 * enumerates a publication's attachments. Per DWN-OR-003 the rendered PDF is an
 * on-demand artefact — it is never saved to Nextcloud user storage by this service.
 *
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-2
 */
class DownloadService {
	/**
	 * Constructor for DownloadService.
	 *
	 * @param FileService $fileService The file service for handling file operations
	 */
	public function __construct(
		private readonly FileService $fileService,
	) {

	}//end __construct()

	/**
	 * Renders the publication metadata PDF and returns it as an in-memory artefact.
	 *
	 * The PDF is written to a temporary location, read back, and the temporary file
	 * is deleted before returning — it is never saved to Nextcloud user storage
	 * (DWN-OR-003). Callers pipe the returned bytes into the download ZIP.
	 *
	 * @param ObjectService $objectService The ObjectService for database access.
	 * @param string|integer $id The id of the Publication to create a pdf for.
	 * @param array|null $options Options for this function.
	 *                            "download" = produce the artefact (true default);
	 *                            when false there is no output option left enabled
	 *                            and the service returns 400 without rendering.
	 *                            "publication" = pre-fetched publication body.
	 *
	 * @return array|JSONResponse ['filename' => string, 'content' => string] or an error response.
	 * @throws LoaderError|RuntimeError|SyntaxError|MpdfException|Exception
	 *
	 * @spec openspec/specs/download-service/spec.md
	 */
	public function createPublicationFile(
		ObjectService $objectService,
		string|int $id,
		?array $options = [
			'download' => true,
			'publication' => null,
		],
	): array|JSONResponse {
		// Validate options before generating any file content (DWN-OR-004).
		if (($options['download'] ?? true) === false) {
			return new JSONResponse(
				data: ['error' => 'At least one output option must be enabled; "download" was false'],
				statusCode: 400
			);
		}

		// Get publication data if not provided (DWN-OR-005 returns 404 when unresolvable).
		$publication = ($options['publication'] ?? $this->getPublicationData(id: $id, objectService: $objectService));
		if ($publication instanceof JSONResponse) {
			return $publication;
		}

		// Create the PDF file using a twig template and publication data.
		$mpdf = $this->fileService->createPdf('publication.html.twig', ['publication' => $publication]);

		// A publication without a title still gets a usable entry name — an undefined
		// key here would surface as a PHP warning on an anonymous-reachable path.
		$title = ($publication['title'] ?? 'publication');
		$filename = "$title.pdf";

		// Render to a temporary location, read it back, and remove the temporary file.
		// The metadata PDF is an on-demand artefact — it MUST NOT be written to
		// Nextcloud user storage by this service (DWN-OR-003).
		$tempDir = sys_get_temp_dir() . '/mpdf';
		if (is_dir($tempDir) === false) {
			mkdir(directory: $tempDir, permissions: 0777, recursive: true);
		}

		$tempPath = $tempDir . '/' . bin2hex(random_bytes(16)) . '.pdf';

		try {
			$mpdf->Output($tempPath, Destination::FILE);
			$content = file_get_contents($tempPath);
		} finally {
			if (file_exists($tempPath) === true) {
				unlink($tempPath);
			}
		}

		if ($content === false) {
			return new JSONResponse(
				data: ['error' => 'Failed to read the rendered publication metadata PDF'],
				statusCode: 500
			);
		}

		return [
			'filename' => $filename,
			'content' => $content,
		];

	}//end createPublicationFile()

	/**
	 * Gets a publication and returns it as serialized array.
	 *
	 * @param string|integer $id The id of a publication.
	 * @param ObjectService $objectService The objectService.
	 *
	 * @return array|JSONResponse The publication found as array or an error JSONResponse.
	 *
	 * @spec openspec/specs/download-service/spec.md
	 */
	private function getPublicationData(string|int $id, ObjectService $objectService): array|JSONResponse {
		try {
			$entity = $objectService->find($id);
			if ($entity !== null) {
				return $entity->jsonSerialize();
			}

			return new JSONResponse(
				data: ['error' => 'Publication not found'],
				statusCode: 404
			);
		} catch (NotFoundExceptionInterface|MultipleObjectsReturnedException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: 500
			);
		} catch (ContainerExceptionInterface|DoesNotExistException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: 500
			);
		}//end try

	}//end getPublicationData()

	/**
	 * Gets all attachments for a publication.
	 *
	 * @param string|integer $id The id of a publication.
	 * @param ObjectService $objectService The objectService.
	 *
	 * @return array|JSONResponse All attachments for the publication or an error JSONResponse.
	 *
	 * @spec openspec/specs/download-service/spec.md
	 */
	public function publicationAttachments(string|int $id, ObjectService $objectService): array|JSONResponse {
		// Fetch attachment objects.
		try {
			// Fetch the publication object by its ID.
			$entity = $objectService->find($id);
			$object = null;
			if ($entity !== null) {
				$object = $entity->jsonSerialize();
			}

			if ($object === null) {
				return new JSONResponse(data: ['error' => 'Publication not found'], statusCode: 500);
			}

			// Fetch attachment objects by their IDs.
			$attachments = [];
			foreach (($object['attachments'] ?? []) as $attId) {
				$attEntity = $objectService->find($attId);
				if ($attEntity !== null) {
					$attachments[] = $attEntity->jsonSerialize();
				}
			}

			return $attachments;
		} catch (NotFoundExceptionInterface|MultipleObjectsReturnedException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: 500
			);
		} catch (ContainerExceptionInterface|DoesNotExistException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: 500
			);
		}//end try

	}//end publicationAttachments()
}//end class
