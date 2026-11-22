<?php

/**
 * The Text column and "Read again" endpoints of a publication's file list
 * (scanned-attachments-become-searchable REQ-SOCR-004). The controller, the
 * overview and the store are real; OpenRegister, the update right and the job
 * queue are doubles.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\AttachmentReadingQueueInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Controller\AttachmentReadingController;
use OCA\OpenCatalogi\Exception\AttachmentReadingRefusedException;
use OCA\OpenCatalogi\Exception\RequestRefusedException;
use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingOverview;
use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingStore;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister's ObjectService as the file list meets it: one publication and its reading states.
 */
class ReadingFakeObjectService {

	/**
	 * The reading states stored, as OpenRegister answers them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * The publication find answers with, or the throwable it throws.
	 *
	 * @var object|null
	 */
	public ?object $publication = null;

	/**
	 * Every find, with its RBAC flag.
	 *
	 * @var array<int, array{id: string, rbac: bool}>
	 */
	public array $finds = [];

	/**
	 * What a search throws, when it fails.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $searchError = null;

	/**
	 * Find one object.
	 *
	 * @param string            $id      The uuid.
	 * @param ObjectFindOptions $options The register and schema.
	 * @param bool              $_rbac   Whether RBAC applies.
	 *
	 * @return object
	 */
	public function find(string $id, ObjectFindOptions $options=new ObjectFindOptions(), bool $_rbac=true): object {
		$this->finds[] = ['id' => $id, 'rbac' => $_rbac];
		if ($this->publication instanceof \Throwable) {
			throw $this->publication;
		}

		if ($this->publication === null) {
			throw new DoesNotExistException(msg: 'not there');
		}

		return $this->publication;

	}//end find()

	/**
	 * Search objects.
	 *
	 * @param array<string, mixed> $query         The query.
	 * @param bool                 $_rbac         Whether RBAC applies.
	 * @param bool                 $_multitenancy Whether multitenancy applies.
	 *
	 * @return array{results: array<int, array<string, mixed>>}
	 */
	public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
		if ($this->searchError !== null) {
			throw $this->searchError;
		}

		return ['results' => $this->rows];

	}//end searchObjectsPaginated()
}//end class

/**
 * Tests for AttachmentReadingController over the real overview and store.
 */
#[CoversClass(AttachmentReadingController::class)]
#[UsesClass(AttachmentReadingOverview::class)]
#[UsesClass(AttachmentReadingStore::class)]
#[UsesClass(AttachmentReadingRefusedException::class)]
#[UsesClass(RequestRefusedException::class)]
final class AttachmentReadingControllerTest extends TestCase {

	private const PUBLICATION = 'pub-1';

	private const UPDATED = '2026-11-20T10:00:00+00:00';

	/**
	 * The OpenRegister double.
	 *
	 * @var ReadingFakeObjectService
	 */
	private ReadingFakeObjectService $objects;

	/**
	 * The update right.
	 *
	 * @var PublicationRightsInterface&MockObject
	 */
	private PublicationRightsInterface $rights;

	/**
	 * The job queue.
	 *
	 * @var AttachmentReadingQueueInterface&MockObject
	 */
	private AttachmentReadingQueueInterface $queue;

	/**
	 * Set up a readable publication with a typed PDF and a scan without OCR.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new ReadingFakeObjectService();
		$this->objects->publication = (object)['id' => self::PUBLICATION];
		$this->objects->rows = [
			['fileId' => 11, 'publication' => self::PUBLICATION, 'state' => 'typed', 'updatedAt' => self::UPDATED],
			[
				'fileId' => 12,
				'publication' => self::PUBLICATION,
				'state' => 'unavailable',
				'reason' => 'OCR is not installed',
				'updatedAt' => self::UPDATED,
			],
		];
		$this->rights = $this->createMock(originalClassName: PublicationRightsInterface::class);
		$this->queue = $this->createMock(originalClassName: AttachmentReadingQueueInterface::class);

	}//end setUp()

	/**
	 * The controller over the real overview and store.
	 *
	 * @param bool   $installed Whether OpenRegister is installed.
	 * @param string $schema    The configured attachmentReading schema.
	 *
	 * @return AttachmentReadingController
	 */
	private function controller(bool $installed=true, string $schema='31'): AttachmentReadingController {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => match ($key) {
				'publication_register' => '3',
				'attachment_reading_schema' => $schema,
				default => $default,
			}
		);
		$lookup = $this->createMock(originalClassName: OpenRegisterLookup::class);
		$lookup->method('isInstalled')->willReturn($installed);
		$lookup->method('objectService')->willReturn($this->objects);
		$lookup->method('registerConfiguration')->willReturn(['register' => '3', 'schema' => '7']);

		$overview = new AttachmentReadingOverview(
			store: new AttachmentReadingStore(config: $config, openRegister: $lookup),
			queue: $this->queue,
			rights: $this->rights,
			openRegister: $lookup
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new AttachmentReadingController(
			appName: 'opencatalogi',
			request: $this->createMock(originalClassName: IRequest::class),
			l10n: $l10n,
			overview: $overview
		);

	}//end controller()

	/**
	 * An editor sees which attachment search can read, and may read again.
	 *
	 * @return void
	 */
	public function testAnEditorSeesWhichScanSearchCannotRead(): void {
		$this->rights->method('mayUpdate')->willReturn(true);

		$response = $this->controller()->index(id: self::PUBLICATION);

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'readings' => [
					['fileId' => 11, 'state' => 'typed', 'reason' => '', 'updatedAt' => self::UPDATED],
					['fileId' => 12, 'state' => 'unavailable', 'reason' => 'OCR is not installed', 'updatedAt' => self::UPDATED],
				],
				'canReadAgain' => true,
			],
			actual: $response->getData()
		);
		$this->assertSame(expected: [['id' => self::PUBLICATION, 'rbac' => true]], actual: $this->objects->finds);

	}//end testAnEditorSeesWhichScanSearchCannotRead()

	/**
	 * A reader without the update right sees the states but may not read again.
	 *
	 * @return void
	 */
	public function testAReaderSeesTheStatesWithoutReadAgain(): void {
		$this->rights->method('mayUpdate')->willReturn(false);

		$data = $this->controller()->index(id: self::PUBLICATION)->getData();

		$this->assertFalse(condition: $data['canReadAgain']);
		$this->assertCount(expectedCount: 2, haystack: $data['readings']);

	}//end testAReaderSeesTheStatesWithoutReadAgain()

	/**
	 * A publication the caller cannot read answers 404 and shows no states.
	 *
	 * @return void
	 */
	public function testAPublicationTheCallerCannotReadIsNotFound(): void {
		$this->objects->publication = null;

		$response = $this->controller()->index(id: self::PUBLICATION);

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: 'not-found', actual: $response->getData()['error']);

	}//end testAPublicationTheCallerCannotReadIsNotFound()

	/**
	 * A register that cannot be reached answers 503, not 404.
	 *
	 * @return void
	 */
	public function testAnUnreachableRegisterIsNotConfigured(): void {
		$this->objects->publication = new \RuntimeException(message: 'database gone');

		$response = $this->controller()->index(id: self::PUBLICATION);

		$this->assertSame(expected: 503, actual: $response->getStatus());
		$this->assertSame(expected: 'not-configured', actual: $response->getData()['error']);

	}//end testAnUnreachableRegisterIsNotConfigured()

	/**
	 * Without the schema or OpenRegister the file list is told reading is not set up.
	 *
	 * @return void
	 */
	public function testWithoutTheSchemaReadingIsNotSetUp(): void {
		$this->assertSame(expected: 503, actual: $this->controller(schema: '')->index(id: self::PUBLICATION)->getStatus());
		$this->assertSame(expected: 503, actual: $this->controller(installed: false)->index(id: self::PUBLICATION)->getStatus());
		$this->assertSame(expected: [], actual: $this->objects->finds);

	}//end testWithoutTheSchemaReadingIsNotSetUp()

	/**
	 * Reading states that cannot be read answer 503 rather than an empty list.
	 *
	 * @return void
	 */
	public function testUnreadableStatesAreNotAnEmptyList(): void {
		$this->objects->searchError = new \RuntimeException(message: 'search failed');

		$this->assertSame(expected: 503, actual: $this->controller()->index(id: self::PUBLICATION)->getStatus());

	}//end testUnreadableStatesAreNotAnEmptyList()

	/**
	 * Read again queues the job with force for an editor.
	 *
	 * @return void
	 */
	public function testReadAgainQueuesTheFileForAnEditor(): void {
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->queue->expects($this->once())->method('queueAgain')->with(12, self::PUBLICATION)->willReturn(true);
		$this->queue->expects($this->never())->method('queue');

		$response = $this->controller()->readAgain(id: self::PUBLICATION, fileId: '12');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['fileId' => 12, 'queued' => true], actual: $response->getData());

	}//end testReadAgainQueuesTheFileForAnEditor()

	/**
	 * A reader without the update right cannot read again.
	 *
	 * @return void
	 */
	public function testReadAgainIsRefusedWithoutTheUpdateRight(): void {
		$this->rights->method('mayUpdate')->willReturn(false);
		$this->queue->expects($this->never())->method('queueAgain');

		$response = $this->controller()->readAgain(id: self::PUBLICATION, fileId: 12);

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: 'forbidden', actual: $response->getData()['error']);

	}//end testReadAgainIsRefusedWithoutTheUpdateRight()

	/**
	 * A file that was never read for this publication cannot be read again through it.
	 *
	 * @return void
	 */
	public function testReadAgainRefusesAFileWithoutAReading(): void {
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->queue->expects($this->never())->method('queueAgain');

		$this->assertSame(expected: 404, actual: $this->controller()->readAgain(id: self::PUBLICATION, fileId: 99)->getStatus());

	}//end testReadAgainRefusesAFileWithoutAReading()

	/**
	 * A job that could not be queued answers 503.
	 *
	 * @return void
	 */
	public function testAJobThatCouldNotBeQueuedIsSaid(): void {
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->queue->method('queueAgain')->willReturn(false);

		$response = $this->controller()->readAgain(id: self::PUBLICATION, fileId: 12);

		$this->assertSame(expected: 503, actual: $response->getStatus());
		$this->assertSame(expected: 'not-queued', actual: $response->getData()['error']);

	}//end testAJobThatCouldNotBeQueuedIsSaid()

	/**
	 * A file id that is not a positive number is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnInvalidFileIdIsRefused(): void {
		$this->assertSame(expected: 400, actual: $this->controller()->readAgain(id: self::PUBLICATION, fileId: 'abc')->getStatus());
		$this->assertSame(expected: 400, actual: $this->controller()->readAgain(id: self::PUBLICATION, fileId: 0)->getStatus());
		$this->assertSame(expected: [], actual: $this->objects->finds);

	}//end testAnInvalidFileIdIsRefused()
}//end class
