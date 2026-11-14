<?php

/**
 * Unit tests for CompletenessListener on OpenRegister's real pre-save events:
 * a new publication starts incomplete, an update is evaluated, a caller cannot
 * set the value, and a failed lookup keeps the stored value.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\CompletenessListener;
use OCA\OpenCatalogi\Service\Publication\CompletenessService;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Listener\CompletenessListener
 * @uses   \OCA\OpenCatalogi\Service\Publication\CompletenessService
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicationStateService
 */
final class CompletenessListenerTest extends TestCase {

	/**
	 * The publication schema id.
	 *
	 * @var string
	 */
	private const SCHEMA = '12';

	/**
	 * The logger double of the listener built last.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A new publication starts incomplete, whatever the caller sent.
	 *
	 * @return void
	 */
	public function testANewPublicationStartsIncomplete(): void {
		$event = new ObjectCreatingEvent($this->entity(data: ['title' => 'x', 'completeness' => 'complete']));

		$this->listener(files: [])->handle($event);

		self::assertSame(['completeness' => 'incomplete'], $event->getModifiedData());
	}//end testANewPublicationStartsIncomplete()

	/**
	 * An update that makes the last document public moves it to complete with completeAt.
	 *
	 * @return void
	 */
	public function testAnUpdateWithEveryDocumentPublicBecomesComplete(): void {
		$old   = $this->entity(data: $this->public() + ['completeness' => 'incomplete']);
		$event = new ObjectUpdatingEvent($this->entity(data: $this->public() + ['expectedDocuments' => 2]), $old);

		$this->listener(files: [['share_token' => 'a'], ['share_token' => 'b']])->handle($event);

		$data = $event->getModifiedData();
		self::assertSame('complete', $data['completeness']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['completeAt']);
	}//end testAnUpdateWithEveryDocumentPublicBecomesComplete()

	/**
	 * A caller that sends complete for an incomplete publication is overruled.
	 *
	 * @return void
	 */
	public function testACallerCannotClaimComplete(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(data: $this->public() + ['expectedDocuments' => 3, 'completeness' => 'complete', 'completeAt' => '2026-01-01T00:00:00+00:00']),
			$this->entity(data: $this->public() + ['completeness' => 'incomplete'])
		);

		$this->listener(files: [['share_token' => 'a']])->handle($event);

		self::assertSame(['completeness' => 'incomplete'], $event->getModifiedData());
	}//end testACallerCannotClaimComplete()

	/**
	 * A failed file lookup keeps the stored value, even against a caller's claim, and logs a warning.
	 *
	 * @return void
	 */
	public function testALookupFailureLeavesTheStoredValueAsItWas(): void {
		$event    = new ObjectUpdatingEvent(
			$this->entity(data: $this->public() + ['completeness' => 'complete']),
			$this->entity(data: $this->public() + ['completeness' => 'incomplete'])
		);
		$listener = $this->listener(files: new RuntimeException('database gone'));
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('could not be evaluated, so the stored value is kept: The files of the publication could not be read: database gone'));

		$listener->handle($event);

		self::assertSame(['completeness' => 'incomplete'], $event->getModifiedData());
	}//end testALookupFailureLeavesTheStoredValueAsItWas()

	/**
	 * Withdrawing a file (its share removed) moves a complete publication back to incomplete, keeping when it was complete.
	 *
	 * @return void
	 */
	public function testWithdrawingAFileMovesBackToIncomplete(): void {
		$stored = $this->public() + ['completeness' => 'complete', 'completeAt' => '2026-11-02T08:00:00+00:00'];
		$event  = new ObjectUpdatingEvent($this->entity(data: $stored), $this->entity(data: $stored));

		$this->listener(files: [['share_token' => 'a'], ['share_token' => null]])->handle($event);

		self::assertSame(['completeness' => 'incomplete', 'completeAt' => '2026-11-02T08:00:00+00:00'], $event->getModifiedData());
	}//end testWithdrawingAFileMovesBackToIncomplete()

	/**
	 * Another schema, and another event, are left alone; earlier modified data is kept.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreLeftAlone(): void {
		$listener = $this->listener(files: []);

		$other = new ObjectUpdatingEvent($this->entity(data: $this->public(), schema: '99'));
		$listener->handle($other);
		self::assertSame([], $other->getModifiedData());

		$created = new ObjectCreatingEvent($this->entity(data: [], schema: '99'));
		$listener->handle($created);
		self::assertSame([], $created->getModifiedData());

		$listener->handle(new Event());

		$event = new ObjectUpdatingEvent($this->entity(data: $this->public()));
		$event->setModifiedData(['endDate' => 'kept']);
		$listener->handle($event);
		self::assertSame(['endDate' => 'kept', 'completeness' => 'complete'], array_diff_key($event->getModifiedData(), ['completeAt' => true]));
	}//end testOtherObjectsAreLeftAlone()

	/**
	 * The listener over a file mapper answering these files, or throwing this exception.
	 *
	 * @param array<int, array<string, mixed>>|RuntimeException $files The files or the failure.
	 *
	 * @return CompletenessListener The listener.
	 */
	private function listener(array|RuntimeException $files): CompletenessListener {
		$mapper = $this->createMock(FileMapper::class);
		if ($files instanceof RuntimeException) {
			$mapper->method('getFilesForObject')->willThrowException($files);
		} else {
			$mapper->method('getFilesForObject')->willReturn($files);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($mapper);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'publication_schema' ? self::SCHEMA : $default)
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new CompletenessListener(
			completeness: new CompletenessService(states: new PublicationStateService(), container: $container),
			config: $config,
			logger: $this->logger
		);
	}//end listener()

	/**
	 * A public publication's properties.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function public(): array {
		return ['title' => 'Besluit', 'publicationDate' => '2026-01-01T00:00:00+00:00'];
	}//end public()

	/**
	 * An object of a schema with these properties.
	 *
	 * @param array<string, mixed> $data   The properties.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data, string $schema = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setUuid('pub-1');
		$entity->setObject($data);

		return $entity;
	}//end entity()
}//end class
