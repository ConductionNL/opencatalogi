<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Contract\PublishedCollectionsServiceInterface;
use OCA\OpenCatalogi\Listener\RuleCategoryListener;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\RuleCategoryStamp;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A record a published collection publishes is filed under its category before the save (REQ-CVS-003).
 *
 * @covers \OCA\OpenCatalogi\Listener\RuleCategoryListener
 * @uses \OCA\OpenCatalogi\Service\Publication\RuleCategoryStamp
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 *
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
 */
class RuleCategoryListenerTest extends TestCase {

	/**
	 * Schema id 31 has the slug "raadsbesluit".
	 */
	private const SCHEMA_ID = '31';

	/**
	 * The schema slugs the container's SchemaMapper was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $lookedUp = [];

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener over configured collections, or over a configuration that cannot be read.
	 *
	 * @param array<int, array<string, mixed>>|null $collections The collections; null for unreadable.
	 *
	 * @return RuleCategoryListener The listener.
	 */
	private function listener(?array $collections): RuleCategoryListener {
		$configured = $this->createMock(PublishedCollectionsServiceInterface::class);
		if ($collections === null) {
			$configured->method('collections')->willThrowException(new UnreadableRuleException(message: 'cannot be read'));
		} else {
			$configured->method('collections')->willReturn($collections);
		}

		$schema = new Schema();
		$schema->setSlug('raadsbesluit');
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $id) use ($schema): ?Schema {
				$this->lookedUp[] = (string)$id;
				return ((string)$id === self::SCHEMA_ID) ? $schema : null;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new RuleCategoryListener(
			stamp: new RuleCategoryStamp(collections: $configured, rules: new PublicationRuleService()),
			container: $container,
			logger: $this->logger
		);
	}//end listener()

	/**
	 * The council decision collection of the scenario, governing the type by slug.
	 *
	 * @param string $recordType The governed type.
	 *
	 * @return array<int, array<string, mixed>> The collections.
	 */
	private static function councilDecisions(string $recordType = 'raadsbesluit'): array {
		return [[
			'recordType' => $recordType,
			'wooCategory' => 'infocat004',
			'anonymousProperties' => ['title'],
			'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'Vastgesteld']],
		]];
	}//end councilDecisions()

	/**
	 * An object of a schema.
	 *
	 * @param array<string, mixed> $data The object.
	 * @param string $schema Its schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(array $data, string $schema = self::SCHEMA_ID): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A council decision with status "Vastgesteld" arrives filed under infocat004.
	 */
	public function testACreatedRecordTheRulePublishesIsFiled(): void {
		$event = new ObjectCreatingEvent(self::entity(['title' => 'Begroting 2027', 'status' => 'Vastgesteld']));

		$this->listener(self::councilDecisions())->handle($event);

		$this->assertSame(['wooCategory' => 'infocat004'], $event->getModifiedData());
		$this->assertSame([self::SCHEMA_ID], $this->lookedUp);
	}//end testACreatedRecordTheRulePublishesIsFiled()

	/**
	 * An update that makes the record publish files it, keeping what other listeners already changed.
	 */
	public function testAnUpdateKeepsOtherChangesAndReadsThem(): void {
		$event = new ObjectUpdatingEvent(self::entity(['status' => 'Concept']), self::entity(['status' => 'Concept']));
		$event->setModifiedData(['status' => 'Vastgesteld', 'rolledBy' => 'x']);

		$this->listener(self::councilDecisions())->handle($event);

		$this->assertSame(['status' => 'Vastgesteld', 'rolledBy' => 'x', 'wooCategory' => 'infocat004'], $event->getModifiedData());
	}//end testAnUpdateKeepsOtherChangesAndReadsThem()

	/**
	 * A collection that names the schema by id needs no slug lookup.
	 */
	public function testACollectionNamingTheSchemaIdNeedsNoLookup(): void {
		$event = new ObjectCreatingEvent(self::entity(['status' => 'Vastgesteld']));

		$this->listener(self::councilDecisions(recordType: self::SCHEMA_ID))->handle($event);

		$this->assertSame(['wooCategory' => 'infocat004'], $event->getModifiedData());
		$this->assertSame([], $this->lookedUp);
	}//end testACollectionNamingTheSchemaIdNeedsNoLookup()

	/**
	 * A record the rule does not publish, or of another schema, is left as it is.
	 */
	public function testARecordThatDoesNotPublishOrIsNotGovernedIsLeftAlone(): void {
		$listener = $this->listener(self::councilDecisions());
		$concept = new ObjectCreatingEvent(self::entity(['status' => 'Concept']));
		$other = new ObjectCreatingEvent(self::entity(['status' => 'Vastgesteld'], schema: '77'));
		$noSchema = new ObjectCreatingEvent(self::entity(['status' => 'Vastgesteld'], schema: ''));

		$listener->handle($concept);
		$listener->handle($other);
		$listener->handle($noSchema);

		$this->assertSame([], $concept->getModifiedData());
		$this->assertSame([], $other->getModifiedData());
		$this->assertSame([], $noSchema->getModifiedData());
		$this->assertSame([self::SCHEMA_ID, '77'], $this->lookedUp);
	}//end testARecordThatDoesNotPublishOrIsNotGovernedIsLeftAlone()

	/**
	 * Without configured collections no schema is looked up and nothing changes.
	 */
	public function testNoCollectionsMeansNoLookup(): void {
		$event = new ObjectCreatingEvent(self::entity(['status' => 'Vastgesteld']));

		$this->listener([])->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertSame([], $this->lookedUp);
	}//end testNoCollectionsMeansNoLookup()

	/**
	 * An unreadable configuration is logged and the save goes ahead unchanged.
	 */
	public function testAnUnreadableConfigurationDoesNotStopTheSave(): void {
		$listener = $this->listener(null);
		$this->logger->expects($this->once())->method('warning')->with(
			'[RuleCategoryListener] The publication rules could not be read; the record is saved without a category from them: cannot be read'
		);
		$event = new ObjectCreatingEvent(self::entity(['status' => 'Vastgesteld']));

		$listener->handle($event);

		$this->assertSame([], $event->getModifiedData());
		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUnreadableConfigurationDoesNotStopTheSave()

	/**
	 * Any other event is ignored.
	 */
	public function testAnotherEventIsIgnored(): void {
		$listener = $this->listener(self::councilDecisions());
		$this->logger->expects($this->never())->method($this->anything());

		$listener->handle(new Event());

		$this->assertSame([], $this->lookedUp);
	}//end testAnotherEventIsIgnored()
}//end class
