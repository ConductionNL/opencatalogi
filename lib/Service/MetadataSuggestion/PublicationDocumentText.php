<?php

/**
 * OpenCatalogi publication document text
 *
 * The text OpenRegister extracted from a publication's documents, for Hermiq
 * to read when it proposes metadata.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\MetadataSuggestion;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the extracted text of a publication's files (design D3).
 *
 * The files come from OpenRegister's object file reader and their text from
 * the chunks its text extraction stored; nothing is extracted here. The caller
 * checks that the user may see the publication before asking.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
 */
class PublicationDocumentText {

	/**
	 * OpenRegister's reader of an object's files.
	 *
	 * @var string
	 */
	public const FILE_READER = 'OCA\\OpenRegister\\Contract\\ObjectFileReaderInterface';

	/**
	 * OpenRegister's store of extracted text chunks.
	 *
	 * @var string
	 */
	public const CHUNKS = 'OCA\\OpenRegister\\Kernel\\Contracts\\ChunkMapperInterface';

	/**
	 * The most characters handed on, so one large document cannot fill the request.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 20000;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Answers whether OpenRegister is installed.
	 * @param ContainerInterface $container Resolves OpenRegister's file reader and chunk store.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The extracted text of every file of the publication, in file order, cut at MAX_LENGTH.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return string The text, empty when there are no files, no extracted text, or it could not be read.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public function forPublication(string $publicationId): string {
		if ($this->appManager->isInstalled('openregister') === false) {
			return '';
		}

		try {
			$files = $this->container->get(self::FILE_READER)->getObjectFiles(objectUuid: $publicationId);
			$chunks = $this->container->get(self::CHUNKS);
		} catch (Throwable $e) {
			$this->logger->warning('[PublicationDocumentText] The publication\'s files could not be read: ' . $e->getMessage());
			return '';
		}

		$parts = [];
		foreach ($files as $file) {
			$text = $this->fileText(chunks: $chunks, fileId: (int)$file->getId());
			if ($text !== '') {
				$parts[] = $text;
			}
		}

		return mb_substr(implode("\n\n", $parts), 0, self::MAX_LENGTH);
	}//end forPublication()

	/**
	 * The extracted text of one file, its chunks joined in order.
	 *
	 * @param object $chunks OpenRegister's chunk store.
	 * @param int $fileId The file id.
	 *
	 * @return string The text, empty when none was extracted or it could not be read.
	 */
	private function fileText(object $chunks, int $fileId): string {
		try {
			$found = $chunks->findBySource(sourceType: 'file', sourceId: $fileId);
		} catch (Throwable $e) {
			$this->logger->warning('[PublicationDocumentText] The text of file ' . $fileId . ' could not be read: ' . $e->getMessage());
			return '';
		}

		$text = [];
		foreach ($found as $chunk) {
			$content = trim((string)$chunk->getTextContent());
			if ($content !== '') {
				$text[] = $content;
			}
		}

		return implode("\n", $text);
	}//end fileText()
}//end class
