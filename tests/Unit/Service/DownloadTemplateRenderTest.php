<?php

/**
 * Renders the real publication metadata template through the real FileService.
 *
 * Kept apart from DownloadServiceTest, where FileService and ObjectService are
 * mocks in every test (download-service task 4.4). A mocked `createPdf()` can never
 * raise a Twig LoaderError, which is how a missing template once went unnoticed;
 * this test resolves and renders the template for real so its absence fails here.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/download-service/tasks.md#4.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The metadata PDF renders from the shipped template.
 *
 * @spec openspec/changes/download-service/tasks.md#4.4
 */
class DownloadTemplateRenderTest extends TestCase {

	/**
	 * Renders publication.html.twig through the real FileService into a PDF.
	 *
	 * @spec openspec/changes/download-service/tasks.md#4.4
	 *
	 * @return void
	 */
	public function testRealPublicationTemplateRendersThroughFileService(): void {
		$fileService = new FileService(
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$downloadService = new DownloadService(fileService: $fileService, l10n: $l10n);

		$publication = [
			'id'          => 'abc-123',
			'title'       => 'Real Template Publication',
			'summary'     => 'A short summary.',
			'description' => 'A longer description of the publication.',
			'status'      => 'published',
			'attachments' => [['title' => 'Attachment One'], 'plain-attachment-id'],
		];

		$result = $downloadService->createPublicationFile(
			objectService: $this->createMock(ObjectService::class),
			id: 'abc-123',
			options: ['download' => true, 'publication' => $publication]
		);

		$this->assertIsArray($result);
		$this->assertSame('Real Template Publication.pdf', $result['filename']);
		$this->assertStringStartsWith('%PDF', $result['content']);

	}//end testRealPublicationTemplateRendersThroughFileService()
}//end class
