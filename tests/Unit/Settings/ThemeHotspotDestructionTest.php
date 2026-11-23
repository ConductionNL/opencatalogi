<?php

/**
 * ThemeHotspotDestructionTest
 *
 * Every publication under an archive hotspot is kept from destruction
 * (REQ-THA-002): the publication schema declares that it inherits the appraisal
 * of its themes, and OpenRegister's own inheritance holds a publication back
 * once one of its subjects is kept permanently, whenever it was filed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
final class ThemeHotspotDestructionTest extends TestCase {

	private const INHERITED = 'OCA\OpenRegister\Service\Archival\InheritedAppraisal';

	private const VALIDATOR = 'OCA\OpenRegister\Service\Schemas\RetentionAnnotationValidator';

	/**
	 * The publication schema as the app imports it: the base merged with every fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function publication(): array {
		$root = dirname(path: __DIR__, levels: 3) . '/lib/Settings';
		$base = json_decode(
			json: (string) file_get_contents(filename: $root . '/publication_register.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$merge = new ReflectionMethod(objectOrMethod: SettingsService::class, method: 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components']['schemas']['publication'];
	}//end publication()

	/**
	 * The publication inherits the appraisal of its themes, at a bumped version, and OpenRegister accepts the declaration.
	 *
	 * @return void
	 */
	public function testThePublicationDeclaresInheritanceFromThemes(): void {
		$publication = self::publication();
		$annotation  = $publication['configuration']['x-openregister-retention'];

		self::assertSame(expected: ['themes'], actual: $annotation['inheritAppraisalFrom']);
		self::assertTrue(
			condition: version_compare(version1: $publication['version'], version2: '0.0.12', operator: '>'),
			message: $publication['version']
		);
		self::assertSame(expected: 'theme', actual: $publication['properties']['themes']['items']['$ref']);

		if (class_exists(self::VALIDATOR) === false) {
			self::markTestSkipped(message: 'OpenRegister with appraisal inheritance is not installed beside this app.');
		}

		$validator = self::VALIDATOR;
		self::assertSame(expected: [], actual: (new $validator())->validate($annotation, $publication['properties']));
	}//end testThePublicationDeclaresInheritanceFromThemes()

	/**
	 * A publication filed before its subject was marked is held back once the subject is kept permanently.
	 *
	 * Runs OpenRegister's own InheritedAppraisal over the shipped publication schema; the subject is read from a double.
	 *
	 * @return void
	 */
	public function testAPublicationFiledBeforeTheMarkingIsNotEligible(): void {
		if (class_exists(self::INHERITED) === false || class_exists('OCA\OpenRegister\Db\Schema') === false) {
			self::markTestSkipped(message: 'OpenRegister with appraisal inheritance is not installed beside this app.');
		}

		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->hydrate(object: self::publication());

		$subject = new ObjectEntity();
		$subject->setUuid('theme-herindeling');
		$subject->setObject(['title' => 'Herindeling gemeente', 'archiveHotspot' => true]);
		$subject->setRetention(['archiefnominatie' => 'blijvend_bewaren']);

		$publication = new ObjectEntity();
		$publication->setUuid('pub-2025');
		$publication->setSchema('7');
		$publication->setCreated(new \DateTime('2025-03-01T10:00:00+00:00'));
		$publication->setObject(['title' => 'Besluit herindeling', 'themes' => ['theme-herindeling']]);
		$publication->setRetention(['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2026-01-01']);

		$schemas = $this->createMock(originalClassName: 'OCA\OpenRegister\Db\SchemaMapperInterface');
		$schemas->method('find')->willReturn($schema);
		$objects = $this->createMock(originalClassName: 'OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface');
		$objects->method('findAcrossAllSources')->willReturnCallback(
			static fn (string $identifier): array => ['object' => $subject]
		);

		$inherited = self::INHERITED;
		$appraisal = new $inherited($objects, $schemas, $this->createMock(originalClassName: LoggerInterface::class));

		self::assertSame(expected: 'inherited_permanent_appraisal:theme-herindeling', actual: $appraisal->holdBack($publication));

		$subject->setRetention([]);
		$fresh = new $inherited($objects, $schemas, $this->createMock(originalClassName: LoggerInterface::class));
		self::assertNull(actual: $fresh->holdBack($publication));
	}//end testAPublicationFiledBeforeTheMarkingIsNotEligible()
}//end class
