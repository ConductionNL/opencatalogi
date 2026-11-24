<?php
/**
 * PublisherIdentityTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Publication\PublisherFileStamp;
use OCA\OpenCatalogi\Service\Publication\PublisherIdentity;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The publisher's RSIN is resolved from the shared organisation and checked with the 11-proef (REQ-PRO-002).
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublisherIdentity
 * @covers \OCA\OpenCatalogi\Service\Publication\PublisherFileStamp
 * @covers \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2
 */
final class PublisherIdentityTest extends TestCase {

	private const ORGANISATION = '3f2b1c4d-0000-4000-8000-000000000001';

	/**
	 * The find() calls the ObjectService double received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * An organisation whose RSIN passes the 11-proef is resolved with its name and TOOI identifier.
	 *
	 * @return void
	 */
	public function testAValidRsinIsResolved(): void {
		$identity = $this->identity(organisation: [
			'name' => 'Gemeente Zuiddrecht',
			'tooi' => 'gm9999',
			'rsin' => '002220647',
		])->of(publication: ['organization' => self::ORGANISATION]);

		self::assertSame(
			expected: [
				'rsin'       => '002220647',
				'name'       => 'Gemeente Zuiddrecht',
				'tooi'       => 'gm9999',
				'rsinStatus' => PublisherIdentity::STATUS_VALID,
				'reason'     => null,
			],
			actual: $identity
		);
		self::assertCount(expectedCount: 1, haystack: $this->finds);
		self::assertSame(expected: self::ORGANISATION, actual: $this->finds[0]['id']);
		self::assertFalse(condition: $this->finds[0]['_rbac']);
		self::assertFalse(condition: $this->finds[0]['_multitenancy']);
	}//end testAValidRsinIsResolved()

	/**
	 * An RSIN that fails the 11-proef is not answered; the reason names the check.
	 *
	 * @return void
	 */
	public function testAnInvalidRsinIsNotStamped(): void {
		$identity = $this->identity(organisation: [
			'name' => 'Gemeente Zuiddrecht',
			'tooi' => 'gm9999',
			'rsin' => '123456789',
		])->of(publication: ['organization' => self::ORGANISATION]);

		self::assertNull(actual: $identity['rsin']);
		self::assertSame(expected: PublisherIdentity::STATUS_MISSING, actual: $identity['rsinStatus']);
		self::assertStringContainsString(needle: '11-proef', haystack: (string) $identity['reason']);
		self::assertSame(expected: 'Gemeente Zuiddrecht', actual: $identity['name']);
		self::assertSame(expected: 'gm9999', actual: $identity['tooi']);
	}//end testAnInvalidRsinIsNotStamped()

	/**
	 * Without an organisation, or with one that cannot be read or has no RSIN, there is no RSIN.
	 *
	 * @return void
	 */
	public function testAMissingOrganisationGivesNoRsin(): void {
		$none = $this->identity(organisation: null)->of(publication: []);
		self::assertNull(actual: $none['rsin']);
		self::assertSame(expected: PublisherIdentity::STATUS_MISSING, actual: $none['rsinStatus']);
		self::assertSame(expected: 'The publication names no organisation.', actual: $none['reason']);
		self::assertSame(expected: [], actual: $this->finds);

		$unread = $this->identity(organisation: null)->of(publication: ['organization' => self::ORGANISATION]);
		self::assertNull(actual: $unread['rsin']);
		self::assertSame(expected: 'The organisation of the publication cannot be read.', actual: $unread['reason']);

		$noRsin = $this->identity(organisation: ['name' => 'Gemeente Zuiddrecht', 'rsin' => '  '])
			->of(publication: ['organization' => ['id' => self::ORGANISATION]]);
		self::assertNull(actual: $noRsin['rsin']);
		self::assertSame(expected: 'The organisation has no RSIN.', actual: $noRsin['reason']);
		self::assertSame(expected: 'Gemeente Zuiddrecht', actual: $noRsin['name']);
		self::assertNull(actual: $noRsin['tooi']);
	}//end testAMissingOrganisationGivesNoRsin()

	/**
	 * A failing OpenRegister is answered as an unreadable organisation, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingLookupGivesNoRsin(): void {
		$lookup = $this->createMock(originalClassName: OpenRegisterLookup::class);
		$lookup->method('registerConfiguration')->willThrowException(new RuntimeException(message: 'not configured'));

		$identity = (new PublisherIdentity(openRegister: $lookup, logger: new NullLogger()))
			->of(publication: ['organization' => self::ORGANISATION]);

		self::assertNull(actual: $identity['rsin']);
		self::assertSame(expected: 'The organisation of the publication cannot be read.', actual: $identity['reason']);
	}//end testAFailingLookupGivesNoRsin()

	/**
	 * The 11-proef itself: nine digits, weights 9..2 and -1, a multiple of eleven, never all zeros.
	 *
	 * @return void
	 */
	public function testTheElevenTest(): void {
		self::assertTrue(condition: PublisherIdentity::passesElevenTest(rsin: '002220647'));
		self::assertTrue(condition: PublisherIdentity::passesElevenTest(rsin: '111222333'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '123456789'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '000000000'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '02220647'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '0022206470'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '00222064a'));
	}//end testTheElevenTest()

	/**
	 * Attaching a document to a publication writes the publisher's RSIN, name and TOOI identifier
	 * into the file's metadata, before the file is published (REQ-PRO-002).
	 *
	 * @return void
	 */
	public function testTheRsinIsWrittenToTheFileMetadataOnAttach(): void {
		$files = new StampFakeFileService();
		$this->writer(
			files: $files,
			stamp: new PublisherFileStamp(identity: $this->identity(organisation: [
				'name' => 'Gemeente Zuiddrecht',
				'tooi' => 'gm9999',
				'rsin' => '002220647',
			]))
		)->attach(publication: $this->publication(), file: $this->document());

		self::assertSame(
			expected: ['addFile besluit.pdf', 'changeFileMetadata 200', 'publishFile 200'],
			actual: $files->log
		);
		self::assertSame(expected: 200, actual: $files->changes[0]->fileId);
		self::assertSame(
			expected: ['rsin:002220647', 'publisherName:Gemeente Zuiddrecht', 'publisherTooi:gm9999'],
			actual: $files->changes[0]->labels
		);
		self::assertSame(expected: self::ORGANISATION, actual: $this->finds[0]['id']);
	}//end testTheRsinIsWrittenToTheFileMetadataOnAttach()

	/**
	 * An RSIN that fails the 11-proef is not stamped; the file says the RSIN is missing and why.
	 *
	 * @return void
	 */
	public function testAnInvalidRsinIsRecordedAsMissingOnAttach(): void {
		$files = new StampFakeFileService();
		$this->writer(
			files: $files,
			stamp: new PublisherFileStamp(identity: $this->identity(organisation: [
				'name' => 'Gemeente Zuiddrecht',
				'tooi' => 'gm9999',
				'rsin' => '123456789',
			]))
		)->attach(publication: $this->publication(), file: $this->document());

		self::assertSame(
			expected: ['rsinStatus:missing', 'rsinReason:The RSIN of the organisation fails the 11-proef.'],
			actual: $files->changes[0]->labels
		);
		self::assertSame(expected: 'publishFile 200', actual: $files->log[2]);
	}//end testAnInvalidRsinIsRecordedAsMissingOnAttach()

	/**
	 * Without a stamp the container can give, the document is still attached and published.
	 *
	 * @return void
	 */
	public function testWithoutAStampTheDocumentIsStillPublished(): void {
		$files = new StampFakeFileService();
		$this->writer(files: $files, stamp: new \stdClass())->attach(publication: $this->publication(), file: $this->document());
		$this->writer(files: $files, stamp: null)->attach(publication: $this->publication(), file: $this->document());

		self::assertSame(
			expected: ['addFile besluit.pdf', 'publishFile 200', 'addFile besluit.pdf', 'publishFile 201'],
			actual: $files->log
		);
		self::assertSame(expected: [], actual: $files->changes);
	}//end testWithoutAStampTheDocumentIsStillPublished()

	/**
	 * A file service that cannot change file metadata gets nothing written, and the stamp says so.
	 *
	 * @return void
	 */
	public function testAFileServiceWithoutMetadataGetsNoStamp(): void {
		$stamp = new PublisherFileStamp(identity: $this->identity(organisation: ['rsin' => '002220647']));

		self::assertNull(actual: $stamp->stamp(files: new \stdClass(), publication: ['organization' => self::ORGANISATION], fileId: 7));
		self::assertSame(expected: [], actual: $this->finds);
	}//end testAFileServiceWithoutMetadataGetsNoStamp()

	/**
	 * The labels name only what is known: an organisation without a name or TOOI identifier gives the RSIN alone.
	 *
	 * @return void
	 */
	public function testTheLabelsNameOnlyWhatIsKnown(): void {
		self::assertSame(
			expected: ['rsin:002220647'],
			actual: PublisherFileStamp::labels(
				identity: ['rsin' => '002220647', 'name' => null, 'tooi' => null, 'rsinStatus' => PublisherIdentity::STATUS_VALID, 'reason' => null]
			)
		);
		self::assertSame(
			expected: ['rsinStatus:missing', 'rsinReason:The publication names no organisation.'],
			actual: PublisherFileStamp::labels(
				identity: [
					'rsin'       => null,
					'name'       => 'Gemeente Zuiddrecht',
					'tooi'       => 'gm9999',
					'rsinStatus' => PublisherIdentity::STATUS_MISSING,
					'reason'     => 'The publication names no organisation.',
				]
			)
		);
	}//end testTheLabelsNameOnlyWhatIsKnown()

	/**
	 * A batch publication writer whose container answers this file service and this stamp.
	 *
	 * @param StampFakeFileService $files The file service double.
	 * @param object|null          $stamp What the container answers for the stamp; null throws.
	 *
	 * @return BatchPublicationWriter The writer.
	 */
	private function writer(StampFakeFileService $files, ?object $stamp): BatchPublicationWriter {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\FileService' => $files,
				PublisherFileStamp::class => ($stamp ?? throw new RuntimeException(message: 'not registered: ' . $id)),
				default => throw new RuntimeException(message: 'not registered: ' . $id),
			}
		);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		return new BatchPublicationWriter(
			rootFolder: $this->createMock(originalClassName: IRootFolder::class),
			container: $container,
			linker: $this->createMock(originalClassName: PublicationLinker::class),
			l10n: $this->createMock(originalClassName: IL10N::class),
			appManager: $apps,
			titles: null
		);
	}//end writer()

	/**
	 * A saved publication of the organisation, as an entity that serialises to its properties.
	 *
	 * @return array{id: string, entity: object, title: string} The publication as BatchPublicationWriter::publication() answers it.
	 */
	private function publication(): array {
		$entity = new class(self::ORGANISATION) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param string $organisation The publisher.
			 */
			public function __construct(private readonly string $organisation) {
			}

			/**
			 * The properties.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'pub-1', 'title' => 'Besluit', 'organization' => $this->organisation];
			}
		};

		return ['id' => 'pub-1', 'entity' => $entity, 'title' => 'Besluit'];
	}//end publication()

	/**
	 * The document to attach.
	 *
	 * @return File The file.
	 */
	private function document(): File {
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getName')->willReturn('besluit.pdf');
		$file->method('fopen')->willReturnCallback(static fn () => fopen('php://memory', 'r+'));

		return $file;
	}//end document()

	/**
	 * A PublisherIdentity over an ObjectService double that answers one organisation.
	 *
	 * @param array<string, mixed>|null $organisation The organisation's properties, or null when it is not found.
	 *
	 * @return PublisherIdentity The identity resolver.
	 */
	private function identity(?array $organisation): PublisherIdentity {
		$this->finds = [];
		$finds       = &$this->finds;
		$objects     = new class($organisation, $finds) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null  $organisation The organisation answered.
			 * @param list<array<string, mixed>> $finds        The calls received.
			 */
			public function __construct(private readonly ?array $organisation, private array &$finds) {
			}

			/**
			 * Answer the organisation.
			 *
			 * @param string $id            The id.
			 * @param mixed  $options       The find options.
			 * @param bool   $_rbac         Whether RBAC applies.
			 * @param bool   $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>|null The organisation.
			 */
			public function find(string $id, mixed $options=null, bool $_rbac=true, bool $_multitenancy=true): ?array {
				$this->finds[] = ['id' => $id, 'options' => $options, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];

				return $this->organisation;
			}
		};

		$lookup = $this->createMock(originalClassName: OpenRegisterLookup::class);
		$lookup->method('registerConfiguration')->willReturn(['register' => 'directory', 'schema' => 'nc-organisation']);
		$lookup->method('installedObjectService')->willReturn($objects);

		return new PublisherIdentity(openRegister: $lookup, logger: new NullLogger());
	}//end identity()
}//end class

/**
 * Double of OpenRegister's FileService: attaches, changes file metadata and publishes, in one log.
 */
class StampFakeFileService {

	/**
	 * The calls, in order.
	 *
	 * @var list<string>
	 */
	public array $log = [];

	/**
	 * The metadata changes received.
	 *
	 * @var list<object>
	 */
	public array $changes = [];

	/**
	 * The number of files attached.
	 *
	 * @var int
	 */
	private int $attached = 0;

	/**
	 * Attach a file and answer it.
	 *
	 * @param mixed  $objectEntity The publication.
	 * @param string $fileName     The name.
	 * @param mixed  $content      The bytes.
	 * @param mixed  ...$rest      The other arguments.
	 *
	 * @return object The file, with its id.
	 */
	public function addFile(mixed $objectEntity, string $fileName, mixed $content, mixed ...$rest): object {
		$this->log[] = 'addFile ' . $fileName;
		$id = (200 + $this->attached);
		$this->attached++;

		return new class($id) {
			/**
			 * Constructor.
			 *
			 * @param int $id The file id.
			 */
			public function __construct(private readonly int $id) {
			}

			/**
			 * The file id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}
		};
	}//end addFile()

	/**
	 * Record a metadata change.
	 *
	 * @param object $change The change.
	 *
	 * @return object The change.
	 */
	public function changeFileMetadata(object $change): object {
		$this->log[]     = 'changeFileMetadata ' . $change->fileId;
		$this->changes[] = $change;

		return $change;
	}//end changeFileMetadata()

	/**
	 * Record a publish.
	 *
	 * @param mixed      $object The publication.
	 * @param string|int $file   The file.
	 *
	 * @return object Nothing of use.
	 */
	public function publishFile(mixed $object, string|int $file): object {
		$this->log[] = 'publishFile ' . $file;

		return new \stdClass();
	}//end publishFile()
}//end class
