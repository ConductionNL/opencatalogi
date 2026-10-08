<?php

/**
 * Unit tests for DocumentRedactor.
 *
 * The redactor hands a partly public Woo document to OpenRegister's redaction
 * pipeline and accepts the result only after verifying it. Every test that
 * does not end in a verified, separate, changed file asserts the same thing:
 * `redactionStatus` is `failed` and `anonymizedDocument` is empty, so there is
 * nothing for the publish to attach and the original never takes its place.
 *
 * The OpenRegister doubles mirror the real signatures (checked against
 * openregister `FileService::anonymizeDocument(Node $node, array $entities, ...)`,
 * `FileService::getLastResidualEntities(): array` and
 * `EntityRelationMapper::findEntitiesForAnonymization(int $fileId): array`).
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
 *
 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCA\OpenCatalogi\Service\Woo\DocumentRedactor;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Double of OpenRegister's FileService, redaction half, with its real parameter names.
 */
class RedactorFakeFileService {

	public mixed $result = null;

	public ?\Throwable $throw = null;

	/** @var array<int, array<string, mixed>> */
	public array $residuals = [];

	/** @var array<int, array<int, array<string, string>>> */
	public array $calls = [];

	public function anonymizeDocument(Node $node, array $entities, string $scope='document', ?string $fileKey=null, ?bool $preserveStructure=null): Node {
		$this->calls[] = $entities;
		if ($this->throw !== null) {
			throw $this->throw;
		}

		return $this->result;
	}//end anonymizeDocument()

	public function getLastResidualEntities(): array {
		return $this->residuals;
	}//end getLastResidualEntities()
}//end class

/**
 * Double of OpenRegister's EntityRelationMapper.
 */
class RedactorFakeEntityRelationMapper {

	/** @var array<int, array<string, mixed>> */
	public array $rows = [];

	/** @var array<int, int> */
	public array $asked = [];

	public function findEntitiesForAnonymization(int $fileId): array {
		$this->asked[] = $fileId;
		return $this->rows;
	}//end findEntitiesForAnonymization()
}//end class

/**
 * Tests for DocumentRedactor.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\DocumentRedactor
 */
class DocumentRedactorTest extends TestCase {

	private RedactorFakeFileService $files;

	private RedactorFakeEntityRelationMapper $relations;

	private File $original;

	private BatchPublicationWriter $writer;

	private ContainerInterface $container;

	private IL10N $l10n;

	private bool $installed = true;

	private bool $containerBroken = false;

	protected function setUp(): void {
		$this->files = new RedactorFakeFileService();
		$this->relations = new RedactorFakeEntityRelationMapper();
		$this->relations->rows = [
			['entity_value' => 'Jan Jansen', 'entity_type' => 'PERSON'],
			['entity_value' => 'Jan Jansen', 'entity_type' => 'PERSON'],
			['entity_value' => '0612345678', 'entity_type' => 'PHONE'],
		];
		$this->original = $this->file(id: 11, content: 'Jan Jansen belde 0612345678');
		$this->files->result = $this->file(id: 12, content: '[PERSOON: 1] belde [TELEFOON: 2]');

		$this->writer = $this->createMock(BatchPublicationWriter::class);
		$this->writer->method('resolve')->willReturnCallback(
			fn (string $reference, string $owner): ?File => ($reference === 'doc-c' && $owner === 'alice') ? $this->original : null
		);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->containerBroken === true) {
					throw new \RuntimeException('not registered');
				}

				return match ($id) {
					'OCA\OpenRegister\Service\FileService' => $this->files,
					'OCA\OpenRegister\Db\EntityRelationMapper' => $this->relations,
				};
			}
		);

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, mixed $parameters=[]): string => vsprintf($text, (array)$parameters)
		);

	}//end setUp()

	/**
	 * A file double with an id and content, hashed like Nextcloud does.
	 *
	 * @param int    $id      The file id.
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
	 * @return DocumentRedactor
	 */
	private function redactor(): DocumentRedactor {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => $this->installed);
		return new DocumentRedactor($this->writer, $this->container, $this->l10n, $this->createMock(LoggerInterface::class), $apps);
	}//end redactor()

	/**
	 * Redact the standard partly public assessment.
	 *
	 * @return array<string, string>
	 */
	private function redact(): array {
		return $this->redactor()->redact(['documentReference' => 'doc-c'], 'alice');
	}//end redact()

	/**
	 * Assert a result leaves nothing to publish.
	 *
	 * @param array<string, string> $result The redaction result.
	 * @param string                $reason A fragment of the officer's reason.
	 *
	 * @return void
	 */
	private function assertFailedClosed(array $result, string $reason): void {
		$this->assertSame(DocumentRedactor::FAILED, $result['redactionStatus']);
		$this->assertSame('', $result['anonymizedDocument']);
		$this->assertSame('', $result['anonymizedDocumentHash']);
		$this->assertStringContainsString($reason, $result['redactionMessage']);
	}//end assertFailedClosed()

	public function testAVerifiedRedactionPointsAtTheRedactedFileAndItsBytes(): void {
		$result = $this->redact();

		$this->assertSame(DocumentRedactor::VERIFIED, $result['redactionStatus']);
		$this->assertSame('12', $result['anonymizedDocument']);
		$this->assertSame(hash('sha256', '[PERSOON: 1] belde [TELEFOON: 2]'), $result['anonymizedDocumentHash']);
		$this->assertSame('', $result['redactionMessage']);
		$this->assertSame([11], $this->relations->asked);
	}//end testAVerifiedRedactionPointsAtTheRedactedFileAndItsBytes()

	public function testTheFindingsGoToOpenRegisterOncePerValue(): void {
		$this->redact();

		$this->assertSame(
			[
				['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => substr(md5('Jan JansenPERSON'), 0, 8)],
				['text' => '0612345678', 'entityType' => 'PHONE', 'key' => substr(md5('0612345678PHONE'), 0, 8)],
			],
			$this->files->calls[0]
		);
	}//end testTheFindingsGoToOpenRegisterOncePerValue()

	public function testAFailingRedactionCallFailsClosed(): void {
		$this->files->throw = new \RuntimeException('Jan Jansen survived re-extraction');
		$this->assertFailedClosed($this->redact(), 'Redaction failed in OpenRegister');
	}//end testAFailingRedactionCallFailsClosed()

	public function testWithoutOpenRegisterNothingIsRedactedOrPublishable(): void {
		$this->installed = false;
		$this->assertFailedClosed($this->redact(), 'OpenRegister is not installed');
		$this->assertSame([], $this->files->calls);
	}//end testWithoutOpenRegisterNothingIsRedactedOrPublishable()

	public function testAnUnresolvableRedactionServiceFailsClosed(): void {
		$this->containerBroken = true;
		$this->assertFailedClosed($this->redact(), 'Redaction is unavailable in OpenRegister');
	}//end testAnUnresolvableRedactionServiceFailsClosed()

	public function testAMissingOriginalFailsClosed(): void {
		$result = $this->redactor()->redact(['documentReference' => 'gone'], 'alice');
		$this->assertFailedClosed($result, 'cannot be found');
	}//end testAMissingOriginalFailsClosed()

	public function testUnreadableFindingsFailClosed(): void {
		$relations = $this->createMock(RedactorFakeEntityRelationMapper::class);
		$relations->method('findEntitiesForAnonymization')->willThrowException(new \RuntimeException('db down'));
		$this->relations = $relations;
		$this->assertFailedClosed($this->redact(), 'cannot be read');
	}//end testUnreadableFindingsFailClosed()

	public function testNoFindingsMeansNoRedactedVersionRatherThanTheOriginal(): void {
		$this->relations->rows = [];
		$this->assertFailedClosed($this->redact(), 'no findings to redact');
		$this->assertSame([], $this->files->calls);
	}//end testNoFindingsMeansNoRedactedVersionRatherThanTheOriginal()

	public function testResidualFindingsFailClosed(): void {
		$this->files->residuals = [['text' => 'Jan Jansen', 'type' => 'PERSON', 'id' => '1']];
		$this->assertFailedClosed($this->redact(), '1 findings are still readable');
	}//end testResidualFindingsFailClosed()

	public function testTheOriginalReturnedAsTheRedactedFileFailsClosed(): void {
		$this->files->result = $this->original;
		$this->assertFailedClosed($this->redact(), 'did not return a separate redacted file');
	}//end testTheOriginalReturnedAsTheRedactedFileFailsClosed()

	public function testARedactedFileWithTheOriginalsBytesFailsClosed(): void {
		$this->files->result = $this->file(id: 13, content: 'Jan Jansen belde 0612345678');
		$this->assertFailedClosed($this->redact(), 'cannot be verified');
	}//end testARedactedFileWithTheOriginalsBytesFailsClosed()

	public function testUnreadableResidualsFailClosed(): void {
		$this->assertFailedClosed($this->redactor()->verify($this->original, $this->files->result, null), 'cannot be verified');
	}//end testUnreadableResidualsFailClosed()

	public function testAnUnhashableRedactedFileFailsClosed(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(14);
		$file->method('hash')->willThrowException(new \RuntimeException('storage gone'));
		$this->assertFailedClosed($this->redactor()->verify($this->original, $file, []), 'cannot be verified');
	}//end testAnUnhashableRedactedFileFailsClosed()
}//end class
