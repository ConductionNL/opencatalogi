<?php

/**
 * The publication schema says who can read it (REQ-PSR-001).
 *
 * Reads the shipped register the way the instance imports it: the monolith with
 * every register.d fragment merged onto it by SettingsService's own merge.
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
 * @spec openspec/changes/publications-for-signed-in-readers/tasks.md#1-the-schema-and-the-rule
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
final class PublicationAudienceSchemaTest extends TestCase {

	/**
	 * The merged register configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function merged(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');
		return $merge->invoke(null, $base, $root . '/register.d');
	}//end merged()

	/**
	 * The publication carries audience and minimumAssurance with their enums and defaults.
	 *
	 * @return void
	 */
	public function testThePublicationSaysWhoCanReadIt(): void {
		$publication = $this->merged()['components']['schemas']['publication'];

		self::assertSame(['public', 'signedIn'], $publication['properties']['audience']['enum']);
		self::assertSame('public', $publication['properties']['audience']['default']);
		self::assertSame('Who can read it', $publication['properties']['audience']['title']);
		self::assertSame(['substantial', 'high'], $publication['properties']['minimumAssurance']['enum']);
		self::assertSame('substantial', $publication['properties']['minimumAssurance']['default']);
		self::assertNotSame('', $publication['properties']['minimumAssurance']['description']);
	}//end testThePublicationSaysWhoCanReadIt()

	/**
	 * The merged publication schema version is bumped, so the importer takes the new properties.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionIsBumped(): void {
		$publication = $this->merged()['components']['schemas']['publication'];

		self::assertSame('0.0.9', $publication['version']);
	}//end testTheSchemaVersionIsBumped()

	/**
	 * Every public read rule of the publication admits only audience public (REQ-PSR-002).
	 *
	 * @return void
	 */
	public function testEveryPublicReadRuleMatchesOnlyAudiencePublic(): void {
		$rules = $this->merged()['components']['schemas']['publication']['authorization']['read'];

		$public = array_values(array_filter($rules, static fn (mixed $rule): bool => is_array($rule) === true && ($rule['group'] ?? null) === 'public'));
		self::assertNotSame([], $public);
		foreach ($public as $rule) {
			self::assertSame('public', $rule['match']['audience'] ?? null, json_encode($rule));
		}
	}//end testEveryPublicReadRuleMatchesOnlyAudiencePublic()

	/**
	 * A consumer carries actForSignedInReaders, off unless an administrator sets it (REQ-PSR-003).
	 *
	 * @return void
	 */
	public function testAConsumerMayActForSignedInReadersOnlyWhenSet(): void {
		$consumer = $this->merged()['components']['schemas']['feedConsumer'];

		self::assertSame('boolean', $consumer['properties']['actForSignedInReaders']['type']);
		self::assertFalse($consumer['properties']['actForSignedInReaders']['default']);
		self::assertSame('0.1.1', $consumer['version']);
		self::assertSame(['admin'], $consumer['authorization']['update']);
	}//end testAConsumerMayActForSignedInReadersOnlyWhenSet()
}//end class
