<?php

/**
 * Unit tests for PublicationLinksListener (REQ-PRS-001, REQ-PRS-003).
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

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\PublicationLinksListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A self-relation is refused before the write; a case reference is mirrored into the source identifiers.
 *
 * @covers \OCA\OpenCatalogi\Listener\PublicationLinksListener
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationLinkRules
 */
class PublicationLinksListenerTest extends TestCase {

	/**
	 * The listener over a configuration naming register 7 and schema 12 as the publication schema.
	 *
	 * @param bool $configured Whether the publication register and schema are configured.
	 *
	 * @return PublicationLinksListener The listener.
	 */
	private function listener(bool $configured = true): PublicationLinksListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $configured === true
				? (['publication_register' => '7', 'publication_schema' => '12'][$key] ?? $default)
				: $default
		);

		return new PublicationLinksListener($config);
	}//end listener()

	/**
	 * An object of the given schema with the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 * @param string $schema The schema id.
	 * @param string|null $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(array $data, string $schema = '12', ?string $uuid = 'pub-a'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('7');
		$entity->setSchema($schema);
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end object()

	/**
	 * A publication saved with its own id in `replaces` is refused naming the property.
	 *
	 * @return void
	 */
	public function testASelfRelationIsRefused(): void {
		$event = new ObjectUpdatingEvent(self::object(['title' => 'Parkeerbesluit', 'replaces' => ['pub-b', 'pub-a']]), self::object(['title' => 'Parkeerbesluit']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('replaces', $event->getErrors()['property']);
		$this->assertStringContainsString('"replaces"', $event->getErrors()['message']);
	}//end testASelfRelationIsRefused()

	/**
	 * A relation to another publication passes.
	 *
	 * @return void
	 */
	public function testARelationToAnotherPublicationPasses(): void {
		$event = new ObjectUpdatingEvent(self::object(['replaces' => ['pub-b'], 'relatedTo' => [['id' => 'pub-c']]]));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
		$this->assertSame([], $event->getModifiedData());
	}//end testARelationToAnotherPublicationPasses()

	/**
	 * The payload dossiq's WooPublicationService::buildPayload() writes gets its case reference mirrored.
	 *
	 * @return void
	 */
	public function testTheDossiqPayloadIsMirrored(): void {
		$payload = [
			'title' => 'Besluit op Woo-verzoek',
			'summary' => 'Samenvatting',
			'publicationKind' => 'woo-besluit',
			'caseReference' => '0b7a2c64-0d2e-4f1d-9d55-0d9a3f2a6e11',
			'period' => ['from' => '2026-01-01', 'to' => '2026-03-31'],
		];
		$event = new ObjectCreatingEvent(self::object($payload, uuid: null));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(
			['sourceIdentifiers' => [['system' => 'case', 'identifier' => '0b7a2c64-0d2e-4f1d-9d55-0d9a3f2a6e11', 'public' => false]]],
			$event->getModifiedData()
		);
	}//end testTheDossiqPayloadIsMirrored()

	/**
	 * An entry for the same case is not added twice, and modifications of earlier listeners are read and kept.
	 *
	 * @return void
	 */
	public function testAnExistingEntryIsNotDuplicated(): void {
		$entry = ['system' => 'case', 'identifier' => 'Z-1', 'public' => false];
		$event = new ObjectUpdatingEvent(self::object(['caseReference' => 'Z-1', 'sourceIdentifiers' => [$entry]]));
		$this->listener()->handle($event);
		$this->assertSame([], $event->getModifiedData());

		$event = new ObjectUpdatingEvent(self::object(['caseReference' => 'Z-1']));
		$event->setModifiedData(['sourceIdentifiers' => [$entry], 'other' => 1]);
		$this->listener()->handle($event);
		$this->assertSame(['sourceIdentifiers' => [$entry], 'other' => 1], $event->getModifiedData());
	}//end testAnExistingEntryIsNotDuplicated()

	/**
	 * Objects of another schema, an unconfigured app and other events are left alone.
	 *
	 * @return void
	 */
	public function testOtherObjectsAndEventsAreLeftAlone(): void {
		$data = ['caseReference' => 'Z-1', 'replaces' => ['pub-a']];

		$event = new ObjectCreatingEvent(self::object($data, schema: '99'));
		$this->listener()->handle($event);
		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getModifiedData());

		$event = new ObjectCreatingEvent(self::object($data));
		$this->listener(configured: false)->handle($event);
		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getModifiedData());

		$created = new ObjectCreatedEvent(self::object($data));
		$this->listener()->handle($created);
		$this->assertFalse($created->isPropagationStopped());
	}//end testOtherObjectsAndEventsAreLeftAlone()
}//end class
