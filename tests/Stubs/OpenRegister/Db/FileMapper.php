<?php
/**
 * Stub for OCA\OpenRegister\Db\FileMapper.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for FileMapper.
 */
class FileMapper
{

    /**
     * Stub for findByFileId.
     *
     * @param integer $fileId The file ID.
     *
     * @return mixed
     */
    public function findByFileId(int $fileId): mixed
    {
        return null;

    }//end findByFileId()


    /**
     * Stub for getFiles.
     *
     * @return array<mixed>
     */
    public function getFiles(): array
    {
        return [];

    }//end getFiles()


    /**
     * Stub for getFilesForObject.
     *
     * @param ObjectEntity $object The object entity.
     *
     * @return array<mixed>
     */
    public function getFilesForObject(ObjectEntity $object): array
    {
        return [];

    }//end getFilesForObject()


}//end class
