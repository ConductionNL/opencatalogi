<?php

/**
 * Unit tests for BatchPublicationWriter's publication lookup and refusals.
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

use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Double of OpenRegister's ObjectService for the publication lookup.
 */
class WriterFakeObjectService {

	public ?\Throwable $findThrows = null;

	public mixed $found = null;

	public mixed $saved = null;

	/** @var array<int, array<string, mixed>> */
	public array $saves = [];

	/** @var array<int, string> */
	public array $finds = [];

	public function find(string $id, mixed ...$rest): mixed {
		$this->finds[] = $id;
		if ($this->findThrows !== null) {
			throw $this->findThrows;
		}

		return $this->found;
	}//end find()

	public function saveObject(array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
		$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return $this->saved;
	}//end saveObject()
}//end class

/**
 * Tests for BatchPublicationWriter.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter
 * @uses   \OCA\OpenCatalogi\Service\Woo\DocumentRedactor
 */
class BatchPublicationWriterEdgesTest extends TestCase {

	private WriterFakeObjectService $objects;

	private bool $installed = true;

	protected function setUp(): void {
		$this->objects = new WriterFakeObjectService();

	}//end setUp()

	/**
	 * The writer under test.
	 *
	 * @param IRootFolder|null $root The root folder, when the test needs one.
	 *
	 * @return BatchPublicationWriter
	 */
	private function writer(?IRootFolder $root = null): BatchPublicationWriter {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->objects;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, mixed $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'openregister' && $this->installed);

		return new BatchPublicationWriter(
			($root ?? $this->createMock(IRootFolder::class)),
			$container,
			$this->createMock(PublicationLinker::class),
			$l10n,
			$apps
		);
	}//end writer()

	public function testAnUntitledMissingDocumentIsNamedByItsReference(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('nope'));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($folder);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Publishing is blocked: these documents cannot be found: Besluit, Woo/bijlage.pdf');

		$this->writer($root)->resolveAll(
			listings: [
				['title' => 'Besluit', 'document' => 'Woo/besluit.pdf'],
				['title' => '', 'document' => 'Woo/bijlage.pdf'],
			],
			owner: 'alice'
		);
	}//end testAnUntitledMissingDocumentIsNamedByItsReference()

	public function testWithoutOpenRegisterNoPublicationIsMade(): void {
		$this->installed = false;

		try {
			$this->writer()->publication(batch: ['title' => 'Besluit'], now: '2026-11-13');
			$this->fail('A publication was made without OpenRegister.');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister is not installed', $e->getMessage());
		}

		$this->assertSame([], $this->objects->saves);
	}//end testWithoutOpenRegisterNoPublicationIsMade()

	public function testAnEarlierPublicationThatIsGoneIsReplacedByANewOne(): void {
		$this->objects->findThrows = new RuntimeException('gone');
		$this->objects->saved = new class {
			public function getUuid(): string {
				return 'pub-new';
			}
		};

		$result = $this->writer()->publication(
			batch: ['title' => 'Besluit', 'caseReference' => 'Z-1', 'wooPublication' => ['publication' => 'pub-old']],
			now: '2026-11-13'
		);

		$this->assertSame(['pub-old'], $this->objects->finds);
		$this->assertSame('pub-new', $result['id']);
		$this->assertSame($this->objects->saved, $result['entity']);
		$this->assertCount(1, $this->objects->saves);
		$this->assertSame('Z-1', $this->objects->saves[0]['object']['caseReference']);
		$this->assertSame('publication', $this->objects->saves[0]['register']);
		$this->assertFalse($this->objects->saves[0]['rbac']);
		$this->assertFalse($this->objects->saves[0]['multitenancy']);
	}//end testAnEarlierPublicationThatIsGoneIsReplacedByANewOne()

	public function testAnEarlierPublicationThatStillExistsIsTakenAsItIs(): void {
		$this->objects->found = (object)['kept' => true];

		$result = $this->writer()->publication(batch: ['wooPublication' => ['publication' => 'pub-old']], now: '2026-11-13');

		$this->assertSame('pub-old', $result['id']);
		$this->assertSame($this->objects->found, $result['entity']);
		$this->assertSame([], $this->objects->saves);
	}//end testAnEarlierPublicationThatStillExistsIsTakenAsItIs()

	public function testASerialisedEntityGivesItsIdFromSelf(): void {
		$this->objects->saved = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'pub-self'], 'id' => 'other'];
			}
		};

		$this->assertSame('pub-self', $this->writer()->publication(batch: [], now: '2026-11-13')['id']);
	}//end testASerialisedEntityGivesItsIdFromSelf()

	public function testAPlainArrayEntityFallsBackToItsId(): void {
		$this->objects->saved = ['id' => 'pub-plain'];

		$this->assertSame('pub-plain', $this->writer()->publication(batch: [], now: '2026-11-13')['id']);
	}//end testAPlainArrayEntityFallsBackToItsId()

	/**
	 * The listings carry only the log's state, never its values, from the assessment (REQ-WRV-003).
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-redaction-log-records-what-was-removed-and-on-which-ground-req-wrv-003
	 *
	 * @return void
	 */
	public function testTheListingCarriesTheLogStateNotItsValues(): void {
		$writer = $this->writer();
		$log = [['relationId' => 7, 'entityType' => 'PERSON', 'positionStart' => 0, 'positionEnd' => 10, 'value' => 'Jan Jansen', 'grounds' => ['5.1.2.e']]];

		$listings = $writer->listings([
			['assessment' => 'deels_openbaar', 'documentReference' => 'a.pdf', 'redactionLog' => $log],
			['assessment' => 'deels_openbaar', 'documentReference' => 'b.pdf'],
			['assessment' => 'openbaar', 'documentReference' => 'c.pdf', 'redactionLog' => $log],
		]);

		$this->assertSame(\OCA\OpenCatalogi\Service\Woo\DocumentRedactor::LOG_GROUNDED, $listings[0]['redactionLogState']);
		$this->assertSame(\OCA\OpenCatalogi\Service\Woo\DocumentRedactor::LOG_UNREADABLE, $listings[1]['redactionLogState']);
		$this->assertArrayNotHasKey('redactionLogState', $listings[2]);
		$this->assertStringNotContainsString('Jan Jansen', json_encode($listings));
	}//end testTheListingCarriesTheLogStateNotItsValues()
}//end class
