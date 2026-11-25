<?php

/**
 * ValueListChoiceListener test: a choice cannot set a normative field.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Listener\ValueListChoiceListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Built on the real OpenRegister pre-save events.
 *
 * @covers \OCA\OpenCatalogi\Listener\ValueListChoiceListener
 */
class ValueListChoiceListenerTest extends TestCase {

	/**
	 * The configured valueListChoice schema id.
	 */
	private const CHOICE_SCHEMA = '41';

	/**
	 * The listener, with the choice schema configured.
	 *
	 * @return ValueListChoiceListener
	 */
	private function listener(): ValueListChoiceListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				ValueListChoiceListener::SCHEMA_KEY => self::CHOICE_SCHEMA,
				default => $default,
			}
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new ValueListChoiceListener(config: $config, l10n: $l10n);
	}//end listener()

	/**
	 * An object of the given schema.
	 *
	 * @param array<string, mixed> $data   The object's data.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data, string $schema = self::CHOICE_SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('choice-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end object()

	/**
	 * A choice of the contact details category.
	 *
	 * @param array<string, mixed> $fields Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function choice(array $fields = []): array {
		return array_merge(
			['scheme' => 'informatiecategorieen', 'entry' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_89ee6784'],
			$fields
		);
	}//end choice()

	/**
	 * A choice that sets the code or the URI is refused, naming the field.
	 *
	 * @return void
	 */
	public function testANormativeFieldIsRefused(): void {
		foreach (['code' => 'infocat099', 'uri' => 'https://example.org/other'] as $field => $value) {
			$event = new ObjectUpdatingEvent(
				$this->object(data: self::choice(fields: [$field => $value, 'localLabel' => 'Contact'])),
				$this->object(data: self::choice())
			);

			$this->listener()->handle(event: $event);

			$this->assertTrue(condition: $event->isPropagationStopped(), message: $field);
			$this->assertSame(expected: $field, actual: $event->getErrors()['property']);
			$this->assertStringContainsString(needle: $field, haystack: $event->getErrors()['message']);
		}
	}//end testANormativeFieldIsRefused()

	/**
	 * A new choice that sets a property a choice does not have is refused too.
	 *
	 * @return void
	 */
	public function testAPropertyAChoiceDoesNotHaveIsRefused(): void {
		$event = new ObjectCreatingEvent($this->object(data: self::choice(fields: ['colour' => 'blue'])));

		$this->listener()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'colour', actual: $event->getErrors()['property']);
	}//end testAPropertyAChoiceDoesNotHaveIsRefused()

	/**
	 * A choice of its own fields, with OpenRegister's own keys, is saved.
	 *
	 * @return void
	 */
	public function testAChoiceOfItsOwnFieldsIsSaved(): void {
		$event = new ObjectCreatingEvent($this->object(data: self::choice(fields: [
			'id' => 'choice-1',
			'@self' => ['schema' => self::CHOICE_SCHEMA],
			'order' => 1,
			'localLabel' => 'Contact en bereikbaarheid',
			'explanation' => 'Hoe u ons bereikt.',
			'hidden' => false,
			'active' => false,
		])));

		$this->listener()->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [], actual: $event->getErrors());
	}//end testAChoiceOfItsOwnFieldsIsSaved()

	/**
	 * An object of another schema is left alone, whatever it holds.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->object(data: ['code' => 'infocat005', 'uri' => 'https://example.org'], schema: '12'));

		$this->listener()->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()
}//end class
