<?php

/**
 * Tests for the Woo pipeline incident group and the open incidents.
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

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\SchemaObjects;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidentSettings;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The schema is a real OpenRegister Schema entity; its mapper, the object service,
 * the app config and the group manager are doubles.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
#[CoversClass(PipelineIncidentSettings::class)]
#[UsesClass(SchemaObjects::class)]
class PipelineIncidentSettingsTest extends TestCase {

	/**
	 * The app config values written, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The schemas the mapper was asked to update.
	 *
	 * @var list<Schema>
	 */
	private array $updated = [];

	/**
	 * The incident schema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Build the settings.
	 *
	 * @param list<string>               $groups      The groups that exist.
	 * @param list<array<string, mixed>> $incidents   The incidents OpenRegister holds.
	 * @param bool                       $updateFails Whether the schema update throws.
	 *
	 * @return PipelineIncidentSettings
	 */
	private function settings(array $groups = ['woo-team'], array $incidents = [], bool $updateFails = false): PipelineIncidentSettings {
		$this->schema = new Schema();
		$this->schema->setAuthorization(['read' => ['admin'], 'roles' => ['woo-incident-receiver' => [], 'other' => ['g1']]]);

		$mapper = new class($this->schema, $updateFails, $this->updated) {
			/**
			 * @param Schema $schema The schema.
			 * @param bool $fails Whether update throws.
			 * @param list<Schema> $updated The updated schemas, by reference.
			 */
			public function __construct(private Schema $schema, private bool $fails, private array &$updated) {
			}

			public function find(string|int $id): Schema {
				if ($id !== 'wooPipelineIncident') {
					throw new \RuntimeException('no schema ' . $id);
				}

				return $this->schema;
			}

			public function update(Schema $schema): Schema {
				if ($this->fails === true) {
					throw new \RuntimeException('database is read-only');
				}

				$this->updated[] = $schema;
				return $schema;
			}
		};
		$objects = new class($incidents) {
			/**
			 * @param list<array<string, mixed>> $incidents The incidents.
			 */
			public function __construct(private array $incidents) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['results' => $this->incidents];
			}
		};

		$openRegister = $this->createMock(OpenRegisterLookup::class);
		$openRegister->method('registerConfiguration')
			->with('woo_register', 'woo_pipeline_incident_schema')
			->willReturn(['register' => 'publication', 'schema' => 'wooPipelineIncident']);
		$openRegister->method('installedService')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
		$openRegister->method('installedObjectService')->willReturn($objects);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('opencatalogi', $app);
				$this->stored[$key] = $value;
				return true;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, $groups, true));

		return new PipelineIncidentSettings(config: $config, groups: $groupManager, openRegister: $openRegister);
	}

	public function testSettingTheGroupAssignsItToTheReceiverRole(): void {
		$settings = $this->settings();

		$this->assertSame('', $settings->group());
		$this->assertSame('woo-team', $settings->setGroup(' woo-team '));

		$this->assertSame('woo-team', $settings->group());
		$this->assertCount(1, $this->updated);
		$this->assertSame(
			['read' => ['admin'], 'roles' => ['woo-incident-receiver' => ['woo-team'], 'other' => ['g1']]],
			$this->schema->getAuthorization()
		);
	}

	public function testClearingTheGroupLeavesTheRoleEmpty(): void {
		$settings = $this->settings();
		$settings->setGroup('woo-team');

		$this->assertSame('', $settings->setGroup(''));

		$this->assertSame('', $this->stored['woo_incident_group']);
		$this->assertSame([], $this->schema->getAuthorization()['roles']['woo-incident-receiver']);
	}

	public function testAGroupThatDoesNotExistIsRefused(): void {
		$settings = $this->settings();

		try {
			$settings->setGroup('nobody-here');
			$this->fail('An unknown group must be refused.');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('The group "nobody-here" does not exist.', $e->getMessage());
		}

		$this->assertSame([], $this->stored);
		$this->assertSame([], $this->updated);
	}

	/**
	 * The setting never names a receiver the notification rule does not reach.
	 */
	public function testARoleThatCannotBeAssignedSavesNoGroup(): void {
		$settings = $this->settings(updateFails: true);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('The incident group could not be assigned to the incident schema: database is read-only');
		try {
			$settings->setGroup('woo-team');
		} finally {
			$this->assertArrayNotHasKey('woo_incident_group', $this->stored);
		}
	}

	public function testOnlyOpenIncidentsAreListedNewestFirst(): void {
		$settings = $this->settings(incidents: [
			['id' => 'i-1', 'kind' => 'plooi-delivery', 'subject' => 'pub-1', 'message' => 'oud', 'at' => '2026-11-15T10:00:00+00:00'],
			['id' => 'i-2', 'kind' => 'diwoo-version', 'subject' => 'diwoo', 'message' => 'opgelost', 'at' => '2026-11-16T10:00:00+00:00', 'resolvedAt' => '2026-11-16T12:00:00+00:00'],
			['id' => 'i-3', 'kind' => 'readiness', 'subject' => 'https://example.org', 'message' => 'nieuw', 'at' => '2026-11-17T10:00:00+00:00', 'resolvedAt' => null],
		]);

		$open = $settings->openIncidents();

		$this->assertSame(['i-3', 'i-1'], array_column($open, 'id'));
		$this->assertSame(
			['id' => 'i-1', 'kind' => 'plooi-delivery', 'subject' => 'pub-1', 'message' => 'oud', 'at' => '2026-11-15T10:00:00+00:00'],
			$open[1]
		);
	}
}
