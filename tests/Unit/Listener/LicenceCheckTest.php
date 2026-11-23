<?php

/**
 * Unit tests for LicenceCheck: a new licence on a catalogue or publication
 * comes from the EU licences authority list, or the save is refused.
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
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Listener\LicenceCheck;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Listener\LicenceCheck
 * @uses \OCA\OpenCatalogi\Service\DcatVocabularyService
 */
final class LicenceCheckTest extends TestCase {

	private const CATALOG_SCHEMA = '3';

	private const PUBLICATION_SCHEMA = '7';

	/**
	 * The listener, on the real vocabulary and schema ids 3 (catalogue) and 7 (publication).
	 *
	 * @return LicenceCheck
	 */
	private function listener(): LicenceCheck {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'catalog_schema' => self::CATALOG_SCHEMA,
				'publication_schema' => self::PUBLICATION_SCHEMA,
				default => '',
			}
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new LicenceCheck(config: $config, vocabulary: new DcatVocabularyService(), l10n: $l10n);
	}//end listener()

	/**
	 * An object in the given schema.
	 *
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $data The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A free-text licence on a new publication is refused, naming the property.
	 *
	 * @return void
	 */
	public function testAnUnlistedLicenceIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(schema: self::PUBLICATION_SCHEMA, data: ['title' => 'Besluit', 'license' => 'vrij te gebruiken']));

		$this->listener()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'license', actual: $event->getErrors()['property']);
		$this->assertStringContainsString(needle: 'EU licences authority list', haystack: $event->getErrors()['message']);
	}//end testAnUnlistedLicenceIsRefused()

	/**
	 * A catalogue whose licence changes to a list member by code, IRI or label is saved.
	 *
	 * @return void
	 */
	public function testAListedLicenceIsSaved(): void {
		$licences = [
			'CC0',
			'http://publications.europa.eu/resource/authority/licence/CC_BY_4_0',
			'https://creativecommons.org/publicdomain/zero/1.0/',
		];
		foreach ($licences as $licence) {
			$event = new ObjectUpdatingEvent(
				$this->entity(schema: self::CATALOG_SCHEMA, data: ['title' => 'Open data', 'dcatLicense' => $licence]),
				$this->entity(schema: self::CATALOG_SCHEMA, data: ['title' => 'Open data'])
			);

			$this->listener()->handle(event: $event);

			$this->assertFalse(condition: $event->isPropagationStopped(), message: $licence);
			$this->assertSame(expected: [], actual: $event->getErrors(), message: $licence);
		}
	}//end testAListedLicenceIsSaved()

	/**
	 * A catalogue's free-text licence changed to another free text is refused on `dcatLicense`.
	 *
	 * @return void
	 */
	public function testAChangedUnlistedCatalogueLicenceIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: self::CATALOG_SCHEMA, data: ['dcatLicense' => 'open']),
			$this->entity(schema: self::CATALOG_SCHEMA, data: ['dcatLicense' => 'vrij'])
		);

		$this->listener()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'dcatLicense', actual: $event->getErrors()['property']);
	}//end testAChangedUnlistedCatalogueLicenceIsRefused()

	/**
	 * A legacy publication keeps its stored free-text licence through an unrelated edit.
	 *
	 * @return void
	 */
	public function testAnUnchangedLegacyLicencePasses(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: self::PUBLICATION_SCHEMA, data: ['title' => 'Nieuwe titel', 'license' => 'vrij te gebruiken']),
			$this->entity(schema: self::PUBLICATION_SCHEMA, data: ['title' => 'Oude titel', 'license' => 'vrij te gebruiken'])
		);

		$this->listener()->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUnchangedLegacyLicencePasses()

	/**
	 * A licence that is not text is refused.
	 *
	 * @return void
	 */
	public function testALicenceThatIsNotTextIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(schema: self::PUBLICATION_SCHEMA, data: ['license' => ['CC0']]));

		$this->listener()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'license', actual: $event->getErrors()['property']);
	}//end testALicenceThatIsNotTextIsRefused()

	/**
	 * No licence, an empty one, or a licence in another schema is left alone.
	 *
	 * @return void
	 */
	public function testNoLicenceOrAnotherSchemaIsLeftAlone(): void {
		$events = [
			new ObjectCreatingEvent($this->entity(schema: self::PUBLICATION_SCHEMA, data: ['title' => 'Besluit'])),
			new ObjectCreatingEvent($this->entity(schema: self::PUBLICATION_SCHEMA, data: ['license' => ''])),
			new ObjectCreatingEvent($this->entity(schema: '12', data: ['license' => 'EUPL-1.2 or anything else'])),
			new ObjectCreatingEvent($this->entity(schema: '', data: ['license' => 'vrij'])),
		];

		foreach ($events as $index => $event) {
			$this->listener()->handle(event: $event);
			$this->assertFalse(condition: $event->isPropagationStopped(), message: (string)$index);
		}
	}//end testNoLicenceOrAnotherSchemaIsLeftAlone()
}//end class
