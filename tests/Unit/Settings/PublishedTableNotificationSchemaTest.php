<?php

/**
 * The failed-import notification as the publishedTable schema declares it.
 *
 * ADR-031: OpenCatalogi declares the notification and OpenRegister dispatches
 * it. These tests read the declaration in
 * lib/Settings/register.d/open-data-tables.json, and run OpenRegister's own
 * NotificationAnnotationValidator on it when OpenRegister is installed next to
 * the app.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md
 */
class PublishedTableNotificationSchemaTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/open-data-tables.json';

	private const VALIDATOR = 'OCA\\OpenRegister\\Notifications\\Service\\Notification\\NotificationAnnotationValidator';

	/**
	 * The publishedTable schema as the app ships it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function tableSchema(): array {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
		return $fragment['components']['schemas']['publishedTable'];
	}

	/**
	 * The rule fires when a table is updated to failed, to the importer and
	 * the table's managers, on Nextcloud notifications, naming the table and
	 * carrying the error.
	 *
	 * @return void
	 */
	public function testTheRuleIsDeclared(): void {
		$schema = $this->tableSchema();
		$rule   = $schema['x-openregister-notifications']['table-import-failed'];

		$this->assertSame(
			['type' => 'updated', 'condition' => ['field' => 'status', 'operator' => 'equals', 'value' => 'failed']],
			$rule['trigger']
		);
		$this->assertTrue($rule['enabled']);
		$this->assertSame(['nc-notification'], $rule['channels']);
		$this->assertSame(
			[['kind' => 'field', 'field' => 'importedBy'], ['kind' => 'object-acl', 'permission' => 'manage']],
			$rule['recipients']
		);
		$this->assertSame('Table {{title}} could not be imported', $rule['subject']['en']);
		$this->assertSame('Tabel {{title}} kon niet worden ingelezen', $rule['subject']['nl']);
		$this->assertSame(['nl' => '{{lastError}}', 'en' => '{{lastError}}'], $rule['message']);
	}//end testTheRuleIsDeclared()

	/**
	 * Every field the rule reads is a property of the schema, and failed is a status.
	 *
	 * @return void
	 */
	public function testTheRuleReadsDeclaredFields(): void {
		$schema = $this->tableSchema();

		foreach (['importedBy', 'title', 'lastError', 'status'] as $field) {
			$this->assertArrayHasKey($field, $schema['properties'], $field);
		}

		$this->assertSame('string', $schema['properties']['importedBy']['type']);
		$this->assertContains('failed', $schema['properties']['status']['enum']);
		$this->assertContains('importing', $schema['properties']['status']['enum']);
		$this->assertSame(
			expected: 'date-time',
			actual: $schema['properties']['failedAt']['format'],
			message: 'the Tables section shows when an import failed (REQ-DFA-004)'
		);
		$this->assertSame(expected: '0.3.0', actual: $schema['version']);
	}//end testTheRuleReadsDeclaredFields()

	/**
	 * OpenRegister's own validator accepts the declaration.
	 *
	 * @return void
	 */
	public function testTheRuleValidates(): void {
		if (class_exists(self::VALIDATOR) === false) {
			$this->markTestSkipped('OpenRegister is not installed next to the app, so its NotificationAnnotationValidator cannot run.');
		}

		$validator = new (self::VALIDATOR)();
		$this->assertSame([], $validator->validate($this->tableSchema()));
	}//end testTheRuleValidates()
}//end class
