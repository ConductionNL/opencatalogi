<?php

/**
 * Unit tests for the frozen marker on OpenCatalogi's document write paths (REQ-PWA-003).
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Publication\FrozenRecord;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A document write on a frozen publication is refused in OpenRegister's words.
 *
 * The other write paths have their own case beside their other tests:
 * `EventServiceTest::testPublishObjectAttachmentsOfAFrozenPublicationSharesNothing`
 * and `PublicationStateControllerTest::testAFileWithdrawalOnAFrozenPublicationIsRefused`.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-is-frozen-with-the-withdrawal-reason-req-pwa-003
 */
#[CoversClass(FrozenRecord::class)]
class FrozenPublicationDocumentWriteTest extends TestCase {

	private const MARKER = ['by' => 'redacteur', 'at' => '2026-11-20T10:00:00+00:00', 'reason' => 'Verkeerde bijlage'];

	private const SENTENCE = 'Cannot write to this object: it was frozen by redacteur on 2026-11-20T10:00:00+00:00. Unfreeze it before changing it.';

	/**
	 * A document attached to a frozen publication is refused before OpenRegister's file service is asked for anything.
	 *
	 * @return void
	 */
	public function testAnUploadToAFrozenPublicationIsRefused(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$writer = new BatchPublicationWriter(
			rootFolder: $this->createMock(originalClassName: IRootFolder::class),
			container: $container,
			linker: $this->createMock(originalClassName: PublicationLinker::class),
			l10n: $this->createMock(originalClassName: IL10N::class),
			appManager: $apps
		);
		$file = $this->createMock(originalClassName: File::class);
		$file->expects($this->never())->method('fopen');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: self::SENTENCE);

		$writer->attach(publication: ['id' => 'pub-0', 'entity' => ['@self' => ['frozen' => self::MARKER]]], file: $file);
	}//end testAnUploadToAFrozenPublicationIsRefused()

	/**
	 * The marker is read from an object array, at `@self` or at the top.
	 *
	 * @return void
	 */
	public function testTheMarkerIsReadFromAnObjectArray(): void {
		$frozen = new FrozenRecord();

		$this->assertSame(expected: self::SENTENCE, actual: $frozen->refusal(record: ['@self' => ['frozen' => self::MARKER]]));
		$this->assertSame(expected: self::SENTENCE, actual: $frozen->refusal(record: ['frozen' => self::MARKER]));
	}//end testTheMarkerIsReadFromAnObjectArray()

	/**
	 * The marker is read from an object entity.
	 *
	 * @return void
	 */
	public function testTheMarkerIsReadFromAnEntity(): void {
		$entity = new ObjectEntity();
		$entity->setFrozen(self::MARKER);

		$this->assertSame(expected: self::SENTENCE, actual: (new FrozenRecord())->refusal(record: $entity));
	}//end testTheMarkerIsReadFromAnEntity()

	/**
	 * A record without a marker, with an empty one, or one that cannot be read, may be written.
	 *
	 * @return void
	 */
	public function testARecordThatIsNotFrozenIsNotRefused(): void {
		$frozen = new FrozenRecord();
		$unreadable = new class {
			/**
			 * A marker that cannot be read.
			 *
			 * @return array<string, mixed>
			 */
			public function getFrozen(): array {
				throw new RuntimeException(message: 'no marker here');
			}//end getFrozen()
		};

		$this->assertNull(actual: $frozen->refusal(record: ['@self' => ['uuid' => 'pub-0']]));
		$this->assertNull(actual: $frozen->refusal(record: ['@self' => ['frozen' => []]]));
		$this->assertNull(actual: $frozen->refusal(record: ['frozen' => 'yes']));
		$this->assertNull(actual: $frozen->refusal(record: new ObjectEntity()));
		$this->assertNull(actual: $frozen->refusal(record: $unreadable));
		$this->assertNull(actual: $frozen->refusal(record: null));
		$this->assertNull(actual: $frozen->refusal(record: 'pub-0'));
	}//end testARecordThatIsNotFrozenIsNotRefused()

	/**
	 * A freeze made on entering a state names it; a marker without actor or date says so.
	 *
	 * @return void
	 */
	public function testTheSentenceNamesTheStateAndWhatIsUnknown(): void {
		$frozen = new FrozenRecord();

		$this->assertSame(
			expected: 'Cannot write to this object: it was frozen by redacteur on 2026-11-20T10:00:00+00:00'
				. ' on entering the state "withdrawn". Unfreeze it before changing it.',
			actual: $frozen->refusal(record: ['frozen' => self::MARKER + ['state' => 'withdrawn']])
		);
		$this->assertSame(
			expected: 'Cannot write to this object: it was frozen by an unknown user on an unrecorded date. Unfreeze it before changing it.',
			actual: $frozen->refusal(record: ['frozen' => ['by' => '', 'reason' => 'Verkeerde bijlage']])
		);
	}//end testTheSentenceNamesTheStateAndWhatIsUnknown()

	/**
	 * The sentence is OpenRegister's own, so the refusal reads the same through its files API and through OpenCatalogi.
	 *
	 * @return void
	 */
	public function testTheSentenceIsOpenRegistersOwn(): void {
		if (class_exists(ObjectStateWriteException::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not on the autoload path.');
		}

		foreach ([self::MARKER, self::MARKER + ['state' => 'withdrawn'], ['reason' => 'Verkeerde bijlage']] as $marker) {
			$entity = new ObjectEntity();
			$entity->setFrozen($marker);

			$this->assertSame(
				expected: ObjectStateWriteException::frozen($entity)->getMessage(),
				actual: (new FrozenRecord())->refusal(record: $entity)
			);
		}
	}//end testTheSentenceIsOpenRegistersOwn()
}//end class
