<?php

/**
 * Unit tests for PublicationVersionListener on OpenRegister's real
 * ObjectUpdatingEvent: a correction keeps the earlier public version, and a
 * failed snapshot refuses the correction.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Listener\PublicationVersionListener;
use OCA\OpenCatalogi\Service\Publication\PublicationVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Listener\PublicationVersionListener
 */
final class PublicationVersionListenerTest extends TestCase {

	private const PUBLICATION_SCHEMA = '7';

	/**
	 * The listener over the given version service, with schema 7 as the publication schema.
	 *
	 * @param PublicationVersionService $versions The version service double.
	 *
	 * @return PublicationVersionListener
	 */
	private function listener(PublicationVersionService $versions): PublicationVersionListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ($key === 'publication_schema') ? self::PUBLICATION_SCHEMA : ''
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PublicationVersionListener(
			config: $config,
			versions: $versions,
			l10n: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A version service double that says the update replaces the public version.
	 *
	 * @param bool $replaces What replacesPublicVersion() answers.
	 *
	 * @return PublicationVersionService&MockObject
	 */
	private function versions(bool $replaces): PublicationVersionService {
		$versions = $this->createMock(originalClassName: PublicationVersionService::class);
		$versions->method('replacesPublicVersion')->willReturn($replaces);
		return $versions;
	}//end versions()

	/**
	 * An object in the given schema.
	 *
	 * @param string $schema The schema id.
	 * @param string $title The title.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, string $title): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('a1b2c3d4-0000-4000-8000-000000000001');
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setObject(['title' => $title, 'status' => 'published']);
		return $entity;
	}//end entity()

	/**
	 * A correction to a public publication snapshots the old object and is saved.
	 *
	 * @return void
	 */
	public function testACorrectionSnapshotsTheOldVersion(): void {
		$old = $this->entity(schema: self::PUBLICATION_SCHEMA, title: 'Besluit parkeren 2026');
		$versions = $this->versions(replaces: true);
		$versions->expects($this->once())->method('snapshot')->with($old)->willReturn(['version' => 1]);
		$event = new ObjectUpdatingEvent($this->entity(schema: self::PUBLICATION_SCHEMA, title: 'Besluit parkeren binnenstad 2026'), $old);

		$this->listener(versions: $versions)->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [], actual: $event->getErrors());
	}//end testACorrectionSnapshotsTheOldVersion()

	/**
	 * When the snapshot fails the update is refused with a message.
	 *
	 * @return void
	 */
	public function testAFailedSnapshotRefusesTheUpdate(): void {
		$versions = $this->versions(replaces: true);
		$versions->method('snapshot')->willThrowException(new RuntimeException('The publicationVersion schema is not configured.'));
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: self::PUBLICATION_SCHEMA, title: 'Besluit parkeren binnenstad 2026'),
			$this->entity(schema: self::PUBLICATION_SCHEMA, title: 'Besluit parkeren 2026')
		);

		$this->listener(versions: $versions)->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertStringContainsString(needle: 'earlier public version could not be kept', haystack: $event->getErrors()['message']);
	}//end testAFailedSnapshotRefusesTheUpdate()

	/**
	 * An update that leaves the public version as it was takes no snapshot.
	 *
	 * @return void
	 */
	public function testAnUpdateThatKeepsThePublicVersionTakesNoSnapshot(): void {
		$versions = $this->versions(replaces: false);
		$versions->expects($this->never())->method('snapshot');
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: self::PUBLICATION_SCHEMA, title: 'Besluit'),
			$this->entity(schema: self::PUBLICATION_SCHEMA, title: 'Besluit')
		);

		$this->listener(versions: $versions)->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUpdateThatKeepsThePublicVersionTakesNoSnapshot()

	/**
	 * Another schema, or a create, is left alone.
	 *
	 * @return void
	 */
	public function testOtherObjectsAndCreatesAreLeftAlone(): void {
		$versions = $this->createMock(originalClassName: PublicationVersionService::class);
		$versions->expects($this->never())->method('replacesPublicVersion');
		$versions->expects($this->never())->method('snapshot');
		$listener = $this->listener(versions: $versions);

		$other = new ObjectUpdatingEvent($this->entity(schema: '3', title: 'Catalogus'), $this->entity(schema: '3', title: 'Oud'));
		$listener->handle(event: $other);
		$create = new ObjectCreatingEvent($this->entity(schema: self::PUBLICATION_SCHEMA, title: 'Nieuw'));
		$listener->handle(event: $create);

		$this->assertFalse(condition: $other->isPropagationStopped());
		$this->assertFalse(condition: $create->isPropagationStopped());
	}//end testOtherObjectsAndCreatesAreLeftAlone()
}//end class
