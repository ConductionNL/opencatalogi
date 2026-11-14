<?php

/**
 * Unit tests for ThemeFeaturedCheckListener.
 *
 * A featured subject without a title is refused on the real OpenRegister
 * pre-save events; every other save passes untouched.
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

use OCA\OpenCatalogi\Listener\ThemeFeaturedCheckListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ThemeFeaturedCheckListener.
 */
#[CoversClass(ThemeFeaturedCheckListener::class)]
class ThemeFeaturedCheckTest extends TestCase {

	private const THEME_SCHEMA = '10';

	private function listener(): ThemeFeaturedCheckListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'theme_schema' ? self::THEME_SCHEMA : $default
		);
		return new ThemeFeaturedCheckListener($config);
	}

	/**
	 * An object of a schema.
	 *
	 * @param array<string, mixed> $data   The object.
	 * @param string               $schema Its schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = self::THEME_SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('2');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}

	public function testAFeaturedSubjectWithoutATitleIsRefused(): void {
		$created = new ObjectCreatingEvent($this->entity(['featured' => true, 'summary' => 'Over parkeren']));
		$this->listener()->handle($created);

		$this->assertTrue($created->isPropagationStopped());
		$this->assertSame(['message' => 'A featured subject needs a title', 'property' => 'title'], $created->getErrors());

		$updated = new ObjectUpdatingEvent(
			$this->entity(['featured' => true, 'title' => '   ']),
			$this->entity(['featured' => false, 'title' => 'Parkeren'])
		);
		$this->listener()->handle($updated);

		$this->assertTrue($updated->isPropagationStopped());
		$this->assertSame('title', $updated->getErrors()['property']);
	}

	/**
	 * Saves that pass.
	 *
	 * @return array<string, array{0: ObjectEntity}>
	 */
	public static function passingSaves(): array {
		$entity = static function (array $data, string $schema = self::THEME_SCHEMA): ObjectEntity {
			$object = new ObjectEntity();
			$object->setSchema($schema);
			$object->setObject($data);
			return $object;
		};

		return [
			'featured with a title' => [$entity(['featured' => true, 'title' => 'Parkeren'])],
			'not featured, no title' => [$entity(['featured' => false])],
			'featured as a string is not featured' => [$entity(['featured' => 'true'])],
			'another schema' => [$entity(['featured' => true], '99')],
			'no schema' => [$entity(['featured' => true], '')],
		];
	}

	#[DataProvider('passingSaves')]
	public function testOtherSavesPass(ObjectEntity $object): void {
		$event = new ObjectCreatingEvent($object);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}

	public function testAnAfterSaveEventIsIgnored(): void {
		$event = new ObjectCreatedEvent($this->entity(['featured' => true]));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}
}
