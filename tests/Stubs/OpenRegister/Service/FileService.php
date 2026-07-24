<?php
/**
 * Stub for OCA\OpenRegister\Service\FileService.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for FileService used by PHPUnit mocks in bare CI.
 */
class FileService
{

    /**
     * Create a share link for a file path.
     *
     * @param string       $path        File path.
     * @param integer|null $shareType   Share type.
     * @param integer|null $permissions Permissions.
     *
     * @return string
     */
    public function createShareLink(string $path, ?int $shareType=3, ?int $permissions=null): string
    {
        return '';

    }//end createShareLink()


    /**
     * Get files for an object.
     *
     * @param mixed        $object           Object entity or string identifier.
     * @param boolean|null $sharedFilesOnly  Only shared files.
     *
     * @return array<mixed>
     */
    public function getFiles(mixed $object, ?bool $sharedFilesOnly=false): array
    {
        return [];

    }//end getFiles()


    /**
     * Format file data.
     *
     * @param array<mixed>        $files         Files to format.
     * @param array<mixed>|null   $requestParams Request parameters.
     *
     * @return array<string,mixed>
     */
    public function formatFiles(array $files, ?array $requestParams=[]): array
    {
        return ['results' => []];

    }//end formatFiles()


    /**
     * Get files for an entity.
     *
     * @param mixed $entity Entity.
     *
     * @return array<mixed>
     */
    public function getFilesForEntity(mixed $entity): array
    {
        return [];

    }//end getFilesForEntity()


    /**
     * Create a ZIP archive of all files attached to an object.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity|string $object  Object entity or UUID string.
     * @param string|null                              $zipName Optional name for the zip file.
     *
     * @return array<string,mixed> Zip file info including path and share link.
     */
    public function createObjectFilesZip(\OCA\OpenRegister\Db\ObjectEntity|string $object, ?string $zipName=null): array
    {
        return [];

    }//end createObjectFilesZip()


}//end class
