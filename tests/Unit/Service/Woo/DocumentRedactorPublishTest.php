<?php

/**
 * Unit tests for DocumentRedactor's publish gate and batch owner lookup.
 *
 * `assertPublishable` refuses a partly public listing unless it points at a
 * verified redacted file that exists, differs from the original and still
 * carries the hash recorded when it was verified. `forAssessment` resolves
 * relative references against the batch's creator.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCA\OpenCatalogi\Service\Woo\DocumentRedactor;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Double of OpenRegister's ObjectService lookup, returning a serialisable entity.
 */
class RedactorFakeObjectService {

	/** @var array<int, string> */
	public array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $batch The batch the lookup returns.
	 */
	public function __construct(private array $batch) {
	}//end __construct()

	public function find(string $id): object {
		$this->asked[] = $id;
		$batch = $this->batch;
		return new class($batch) implements \JsonSerializable {

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The data.
			 */
			public function __construct(private array $data) {
			}//end __construct()

			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};
	}//end find()
}//end class

/**
 * Tests for DocumentRedactor::assertPublishable and forAssessment.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\DocumentRedactor
 */
class DocumentRedactorPublishTest extends TestCase {

	/** @var array<string, File> */
	private array $filesByReference = [];

	/** @var array<int, array{0: string, 1: string}> */
	private array $resolved = [];

	private BatchPublicationWriter $writer;

	protected function setUp(): void {
		$this->writer = $this->createMock(BatchPublicationWriter::class);
		$this->writer->method('resolve')->willReturnCallback(
			function (string $reference, string $owner): ?File {
				$this->resolved[] = [$reference, $owner];
				return $this->filesByReference[$owner . ':' . $reference] ?? null;
			}
		);

	}//end setUp()

	/**
	 * A file double with an id and content.
	 *
	 * @param int $id The file id.
	 * @param string $content The bytes.
	 *
	 * @return File
	 */
	private function file(int $id, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('hash')->willReturnCallback(static fn (string $type): string => hash($type, $content));
		return $file;
	}//end file()

	/**
	 * The redactor under test.
	 *
	 * @param ContainerInterface|null $container The container, when the test needs one.
	 *
	 * @return DocumentRedactor
	 */
	private function redactor(?ContainerInterface $container = null): DocumentRedactor {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, mixed $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		return new DocumentRedactor(
			$this->writer,
			($container ?? $this->createMock(ContainerInterface::class)),
			$l10n,
			$this->createMock(LoggerInterface::class),
			$apps
		);
	}//end redactor()

	/**
	 * A verified partly public listing.
	 *
	 * @param string $hash The recorded hash.
	 *
	 * @return array<string, string>
	 */
	private function listing(string $hash): array {
		return [
			'assessment' => 'deels_openbaar',
			'title' => '',
			'original' => 'origineel.pdf',
			'document' => 'gelakt.pdf',
			'redactionStatus' => DocumentRedactor::VERIFIED,
			'redactionHash' => $hash,
			'redactionLogState' => DocumentRedactor::LOG_GROUNDED,
		];
	}//end listing()

	public function testAVerifiedRedactedFileWithItsRecordedHashIsPublishable(): void {
		$this->filesByReference['alice:gelakt.pdf'] = $this->file(id: 2, content: 'gelakt');
		$this->filesByReference['alice:origineel.pdf'] = $this->file(id: 1, content: 'origineel');

		$this->redactor()->assertPublishable(
			listings: [$this->listing(hash('sha256', 'gelakt')), ['assessment' => 'openbaar', 'title' => 'Openbaar stuk']],
			owner: 'alice'
		);

		$this->assertSame([['gelakt.pdf', 'alice'], ['origineel.pdf', 'alice']], $this->resolved);
	}//end testAVerifiedRedactedFileWithItsRecordedHashIsPublishable()

	public function testAMissingRedactedFileBlocksAndNamesTheOriginalWhenUntitled(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Publishing is blocked: these partly public documents have no verified redacted version: origineel.pdf');

		$this->redactor()->assertPublishable(listings: [$this->listing(hash('sha256', 'gelakt'))], owner: 'alice');
	}//end testAMissingRedactedFileBlocksAndNamesTheOriginalWhenUntitled()

	public function testAnUnhashableRedactedFileBlocks(): void {
		$broken = $this->createMock(File::class);
		$broken->method('getId')->willReturn(3);
		$broken->method('hash')->willThrowException(new RuntimeException('storage gone'));
		$this->filesByReference['alice:gelakt.pdf'] = $broken;

		$listing = $this->listing('abc');
		$listing['title'] = 'Besluit';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no verified redacted version: Besluit');

		$this->redactor()->assertPublishable(listings: [$listing], owner: 'alice');
	}//end testAnUnhashableRedactedFileBlocks()

	public function testARedactedFileWhoseBytesChangedBlocks(): void {
		$this->filesByReference['alice:gelakt.pdf'] = $this->file(id: 2, content: 'later gewijzigd');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('origineel.pdf');

		$this->redactor()->assertPublishable(listings: [$this->listing(hash('sha256', 'gelakt'))], owner: 'alice');
	}//end testARedactedFileWhoseBytesChangedBlocks()

	public function testANonPartlyPublicAssessmentNeedsNoRedaction(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertSame(
			['anonymizedDocument' => '', 'anonymizedDocumentHash' => '', 'redactionStatus' => '', 'redactionMessage' => '', 'redactionLog' => []],
			$this->redactor($container)->forAssessment(assessment: ['assessment' => 'openbaar'], batchId: 'b1', fallbackOwner: 'bob')
		);
	}//end testANonPartlyPublicAssessmentNeedsNoRedaction()

	public function testTheOriginalIsLookedUpUnderTheBatchCreatorFromASerialisedEntity(): void {
		$objects = new RedactorFakeObjectService(['createdBy' => 'alice']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		$result = $this->redactor($container)->forAssessment(
			assessment: ['assessment' => 'deels_openbaar', 'documentReference' => 'origineel.pdf'],
			batchId: 'batch-1',
			fallbackOwner: 'bob'
		);

		$this->assertSame(['batch-1'], $objects->asked);
		$this->assertSame([['origineel.pdf', 'alice']], $this->resolved);
		$this->assertSame(DocumentRedactor::FAILED, $result['redactionStatus']);
	}//end testTheOriginalIsLookedUpUnderTheBatchCreatorFromASerialisedEntity()

	/**
	 * A verified partly public listing whose log state is not grounded.
	 *
	 * @param string $state The log state the listing carries, or null for none.
	 *
	 * @return array<string, string>
	 */
	private function verifiedListingWithLog(?string $state): array {
		$this->filesByReference['alice:gelakt.pdf'] = $this->file(id: 2, content: 'gelakt');
		$this->filesByReference['alice:origineel.pdf'] = $this->file(id: 1, content: 'origineel');
		$listing = $this->listing(hash('sha256', 'gelakt'));
		$listing['title'] = 'Besluit';
		unset($listing['redactionLogState']);
		if ($state !== null) {
			$listing['redactionLogState'] = $state;
		}

		return $listing;
	}//end verifiedListingWithLog()

	/**
	 * A passage without a ground blocks the publish and names the document (REQ-WRV-003).
	 *
	 * @return void
	 */
	public function testAPassageWithoutAGroundBlocksThePublish(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('has a passage without a ground: Besluit');

		$this->redactor()->assertPublishable(listings: [$this->verifiedListingWithLog(DocumentRedactor::LOG_UNGROUNDED)], owner: 'alice');
	}//end testAPassageWithoutAGroundBlocksThePublish()

	/**
	 * An empty log on a redacted document blocks the publish (REQ-WRV-003).
	 *
	 * @return void
	 */
	public function testAnEmptyLogForARedactedDocumentBlocksThePublish(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('redaction log of these partly public documents is missing, empty');

		$this->redactor()->assertPublishable(listings: [$this->verifiedListingWithLog(DocumentRedactor::LOG_EMPTY)], owner: 'alice');
	}//end testAnEmptyLogForARedactedDocumentBlocksThePublish()

	/**
	 * A listing without a readable log blocks the publish (REQ-WRV-003).
	 *
	 * @return void
	 */
	public function testAnUnreadableLogBlocksThePublish(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Besluit');

		$this->redactor()->assertPublishable(listings: [$this->verifiedListingWithLog(null)], owner: 'alice');
	}//end testAnUnreadableLogBlocksThePublish()
}//end class
