<?php

/**
 * The portal detail opt-ins ship switched off.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-show-that-documents-were-withheld-and-why-req-pdp-004
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the register files as they ship.
 *
 * @coversNothing
 */
class PortalDetailSchemaTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * @return array<string, mixed>
	 */
	private function schema(string $file, string $slug): array {
		$data = json_decode((string)file_get_contents(self::SETTINGS . $file), true, 512, JSON_THROW_ON_ERROR);

		return $data['components']['schemas'][$slug];
	}//end schema()

	public function testTheDefaultsAreOff(): void {
		$catalog = $this->schema('publication_register.json', 'catalog');
		$assessment = $this->schema('register.d/fix-woo-capability-provisioning.json', 'wooAssessment');

		$this->assertSame(['type' => 'boolean', 'default' => false], array_intersect_key($catalog['properties']['showWithheld'], ['type' => 1, 'default' => 1]));
		$this->assertSame(['type' => 'boolean', 'default' => false], array_intersect_key($assessment['properties']['titlePublic'], ['type' => 1, 'default' => 1]));
		$this->assertSame(['type' => 'boolean', 'default' => false], array_intersect_key($catalog['properties']['acceptErrorReports'], ['type' => 1, 'default' => 1]));
		$this->assertSame([], $catalog['properties']['errorReportModerators']['default']);
		$this->assertSame(['authenticated'], $catalog['properties']['errorReportModerators']['authorization']['read'], 'anonymous readers never learn the moderator groups');
		$this->assertSame('0.6.0', $catalog['version'], 'a schema change moves its version');
		$this->assertSame('0.3.0', $assessment['version'], 'the base fragment keeps its version; the last fragment carries the bump');
	}//end testTheDefaultsAreOff()

	/**
	 * The demo error reports carry every required field, a status the schema lists and a message within the limit,
	 * and no property the schema does not declare.
	 *
	 * @return void
	 */
	public function testTheErrorReportDemoRowsFitTheSchema(): void {
		$schema = $this->schema('register.d/woo-publication-detail-for-the-portal.json', 'errorReport');
		$demo = json_decode((string)file_get_contents(self::SETTINGS . 'opencatalogi_mock_register.json'), true, 512, JSON_THROW_ON_ERROR);
		$rows = array_values(array_filter($demo['components']['objects'], static fn (array $row): bool => ($row['@self']['schema'] ?? '') === 'errorReport'));

		$this->assertCount(3, $rows);
		$this->assertSame(['new', 'handled', 'dismissed'], array_column($rows, 'status'));
		foreach ($rows as $row) {
			$data = array_diff_key($row, ['@self' => true]);
			$this->assertSame([], array_diff($schema['required'], array_keys($data)));
			$this->assertSame([], array_diff(array_keys($data), array_keys($schema['properties'])));
			$this->assertContains($row['status'], $schema['properties']['status']['enum']);
			$this->assertLessThanOrEqual($schema['properties']['message']['maxLength'], mb_strlen($row['message']));
			$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $row['createdAt']));
		}
	}//end testTheErrorReportDemoRowsFitTheSchema()

	/**
	 * Only the report's own moderator groups (and admins) read and update an error report; nobody but an admin
	 * creates or deletes one through the API, and only an admin moves it to another publication, catalogue, moment
	 * or set of moderator groups.
	 *
	 * @return void
	 */
	public function testAnErrorReportIsReadAndHandledOnlyByItsModerators(): void {
		$schema = $this->schema('register.d/woo-publication-detail-for-the-portal.json', 'errorReport');
		$moderators = ['group' => 'authenticated', 'match' => ['moderators' => ['$contains' => '$user.groups']]];

		$this->assertSame(['admin', $moderators], $schema['authorization']['read']);
		$this->assertSame(['admin', $moderators], $schema['authorization']['update']);
		$this->assertSame(['admin'], $schema['authorization']['create']);
		$this->assertSame(['admin'], $schema['authorization']['delete']);
		$this->assertNotContains('authenticated', $schema['authorization']['read'], 'a signed-in user outside the moderator groups reads no report');
		$this->assertNotContains('authenticated', $schema['authorization']['update'], 'a signed-in user outside the moderator groups changes no report');
		foreach (['publication', 'catalog', 'createdAt', 'moderators'] as $property) {
			$this->assertSame(['admin'], $schema['properties'][$property]['authorization']['update'], $property . ' is fixed for a moderator');
		}
		$this->assertArrayNotHasKey('authorization', $schema['properties']['status'], 'a moderator sets the status');
		$this->assertSame(['admin'], $schema['properties']['contact']['authorization']['read']);
	}//end testAnErrorReportIsReadAndHandledOnlyByItsModerators()
}//end class
