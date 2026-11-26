<?php

/**
 * Who may create and edit a publication.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-edits-a-title
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\Publication\PublishRight;
use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Decision 25 Nov 2026 21:50 CET: the publication schema gives create and update to the publishers and to a new
 * editors group; the public read rules and the publishers-only date fields stay as they are.
 *
 * @coversNothing
 *
 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-edits-a-title
 */
final class PublicationEditorsWriteSchemaTest extends TestCase {

	/**
	 * The publication schema as it is imported: the base register with every register.d fragment merged.
	 *
	 * @return array<string, mixed>
	 */
	private static function publication(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components']['schemas']['publication'];
	}//end publication()

	/**
	 * Publishers and editors may create and update; nobody else is named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-edits-a-title
	 */
	public function testPublishersAndEditorsMayCreateAndUpdate(): void {
		$authorization = self::publication()['authorization'];
		$groups = [['group' => PublishRight::GROUP], ['group' => PublishRight::EDITORS_GROUP]];

		self::assertSame(expected: $groups, actual: $authorization['create']);
		self::assertSame(expected: $groups, actual: $authorization['update']);
	}//end testPublishersAndEditorsMayCreateAndUpdate()

	/**
	 * The read rules and the publishers-only publish fields are unchanged, so an editor still cannot publish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-tries-to-publish
	 */
	public function testAnEditorStillCannotPublish(): void {
		$publication = self::publication();

		self::assertContains(needle: 'authenticated', haystack: $publication['authorization']['read']);
		foreach (['publicationDate', 'depublicationDate'] as $field) {
			self::assertSame(
				expected: ['update' => [['group' => PublishRight::GROUP]]],
				actual: $publication['properties'][$field]['authorization'],
				message: $field
			);
		}
	}//end testAnEditorStillCannotPublish()

	/**
	 * The schema version moved past 0.0.18, so an installed instance imports the new rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-edits-a-title
	 */
	public function testTheVersionMoved(): void {
		$version = self::publication()['version'];

		self::assertTrue(condition: version_compare($version, '0.0.18', '>'), message: $version);
	}//end testTheVersionMoved()
}//end class
