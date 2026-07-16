<?php
/**
 * Service for managing download-related operations.
 *
 * Provides functionality to create and manage publication files and archives, including
 * generating PDFs and ZIP files containing metadata and attachments, and storing files
 * in NextCloud.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Mpdf\MpdfException;
use Exception;

/**
 * Service for managing download-related operations.
 *
 * Provides functionality to create and manage publication files and archives, including
 * generating PDFs and ZIP files containing metadata and attachments, and storing files
 * in NextCloud.
 *
 * @spec openspec/changes/migrate-share-links-to-shares-leaf/tasks.md#task-2
 */
class DownloadService
{
    /**
     * Constructor for DownloadService.
     *
     * @param FileService $fileService The file service for handling file operations
     */
    public function __construct(
        private readonly FileService $fileService
    ) {

    }//end __construct()

    /**
     * Creates a pdf file containing all metadata of the given publication.
     *
     * @param ObjectService  $objectService The ObjectService for database access.
     * @param string|integer $id            The id of the Publication to create a pdf for.
     * @param array|null     $options       Options for this function.
     *                                      "download" and "saveToNextCloud" cannot both be false.
     *                                      "download" = return a download response (true default).
     *                                      "saveToNextCloud" = save file in NextCloud (true default).
     *                                      "publication" = pre-fetched publication body.
     *
     * @return JSONResponse A download response, download URL, or error response.
     * @throws LoaderError|RuntimeError|SyntaxError|MpdfException|Exception
     *
     * @spec openspec/specs/download-service/spec.md
     */
    public function createPublicationFile(
        ObjectService $objectService,
        string|int $id,
        ?array $options=[
            'download'        => true,
            'saveToNextCloud' => true,
            'publication'     => null,
        ]
    ): JSONResponse {
        // Validate options.
        if ($options['download'] === false && $options['saveToNextCloud'] === false) {
            return new JSONResponse(
                data: ['error' => 'Options "download" and "saveToNextCloud" should not both be false'],
                statusCode: 500
            );
        }

        // Get publication data if not provided.
        $publication = ($options['publication'] ?? $this->getPublicationData($id, $objectService));
        if ($publication instanceof JSONResponse) {
            return $publication;
        }

        // Create the PDF file using a twig template and publication data.
        $mpdf = $this->fileService->createPdf('publication.html.twig', ['publication' => $publication]);

        $filename = "{$publication['title']}.pdf";

        // Save to NextCloud if option is set.
        $shareLink = null;
        if ($options['saveToNextCloud'] ?? true) {
            $mpdf->Output($filename, Destination::FILE);
            $shareLink = $this->saveFileToNextCloud($filename, $publication);
            if ($shareLink instanceof JSONResponse) {
                return $shareLink;
            }
        }

        // Download if option is set.
        if ($options['download'] ?? true) {
            $mpdf->Output($filename, Destination::DOWNLOAD);
        }

        // Clean up temporary files.
        rmdir('/tmp/mpdf');

        // Return download URL if saved to NextCloud.
        if ($options['saveToNextCloud'] ?? true) {
            return new JSONResponse(
                [
                    'downloadUrl' => "$shareLink/download",
                    'filename'    => $filename,
                ],
                200
            );
        }

        return new JSONResponse([], 200);

    }//end createPublicationFile()

    /**
     * Gets a publication and returns it as serialized array.
     *
     * @param string|integer $id            The id of a publication.
     * @param ObjectService  $objectService The objectService.
     *
     * @return array|JSONResponse The publication found as array or an error JSONResponse.
     *
     * @spec openspec/specs/download-service/spec.md
     */
    private function getPublicationData(string|int $id, ObjectService $objectService): array|JSONResponse
    {
        try {
            $entity = $objectService->find($id);
            if ($entity !== null) {
                return $entity->jsonSerialize();
            }

            return new JSONResponse(
                data: ['error' => 'Publication not found'],
                statusCode: 404
            );
        } catch (NotFoundExceptionInterface | MultipleObjectsReturnedException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        } catch (ContainerExceptionInterface | DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try

    }//end getPublicationData()

    /**
     * Store a publication metadata file in NextCloud and return its share link.
     *
     * @param string $filename    The filename of the file to store in NextCloud
     * @param array  $publication The publication data for folder creation
     *
     * @return string|JSONResponse A share link url or an error JSONResponse
     * @throws Exception When reading or writing to NextCloud files fails
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/download-service/spec.md
     */
    public function saveFileToNextCloud(string $filename, array $publication): string|JSONResponse
    {
        // Create the Publicaties folder and the Publication specific folder.
        $this->fileService->createFolder(folderPath: 'Publicaties');
        $publicationFolder = $this->fileService->getPublicationFolderName(
            publicationId: $publication['id'],
            publicationTitle: $publication['title']
        );
        $this->fileService->createFolder(folderPath: "Publicaties/$publicationFolder");

        // Save the file to NextCloud.
        $filePath = "Publicaties/$publicationFolder/$filename";
        $created  = $this->fileService->updateFile(
            content: file_get_contents(filename: $filename),
            filePath: $filePath,
            createNew: true
        );

        // Check if file creation was successful.
        if ($created === false) {
            return new JSONResponse(
                data: ['error' => "Failed to upload this file: $filePath to NextCloud"],
                statusCode: 500
            );
        }

        // Request public share via the OpenRegister shares leaf (ADR-022 / FIL-005).
        $shareLink = $this->fileService->createPublicShareLink(relativePath: $filePath);

        return $shareLink;

    }//end saveFileToNextCloud()

    /**
     * Gets all attachments for a publication.
     *
     * @param string|integer $id            The id of a publication.
     * @param ObjectService  $objectService The objectService.
     *
     * @return array|JSONResponse All attachments for the publication or an error JSONResponse.
     *
     * @spec openspec/specs/download-service/spec.md
     */
    public function publicationAttachments(string|int $id, ObjectService $objectService): array|JSONResponse
    {
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
        } catch (NotFoundExceptionInterface | MultipleObjectsReturnedException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        } catch (ContainerExceptionInterface | DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try

    }//end publicationAttachments()
}//end class
