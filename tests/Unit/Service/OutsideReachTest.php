<?php

/**
 * What OpenCatalogi does not stop in staging (instance-staging-mode REQ-STG-001).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\OutsideReach;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * OpenRegister's webhook mapper and object service are the boundaries.
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
#[CoversClass(OutsideReach::class)]
#[UsesClass(OpenRegisterLookup::class)]
final class OutsideReachTest extends TestCase {

	/**
	 * A named row, as an entity or a webhook.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return object
	 */
	private static function row(array $data): object {
		return new class($data) {
			/**
			 * Hold the data.
			 *
			 * @param array<string, mixed> $data The data.
			 */
			public function __construct(private readonly array $data) {
			}//end __construct()

			/**
			 * The webhook name.
			 *
			 * @return string
			 */
			public function getName(): string {
				return (string)$this->data['name'];
			}//end getName()

			/**
			 * The object data.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()
		};
	}//end row()

	/**
	 * The reach over the given container answers.
	 *
	 * @param array<string, object|RuntimeException> $services Service id to service, or what it throws.
	 *
	 * @return OutsideReach
	 */
	private function reach(array $services): OutsideReach {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				$service = ($services[$id] ?? new RuntimeException('not registered: ' . $id));
				if ($service instanceof RuntimeException) {
					throw $service;
				}

				return $service;
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		return new OutsideReach($container, new OpenRegisterLookup($container, $apps));
	}//end reach()

	/**
	 * Enabled webhooks and every synchronisation that is not disabled are named.
	 *
	 * @return void
	 */
	public function testEnabledWebhooksAndSynchronisationsAreNamed(): void {
		$webhooks = new class([self::row(['name' => 'Zaaksysteem']), self::row(['name' => 'Archief'])]) {
			/**
			 * Hold the webhooks.
			 *
			 * @param list<object> $webhooks The webhooks.
			 */
			public function __construct(private readonly array $webhooks) {
			}//end __construct()

			/**
			 * The enabled webhooks.
			 *
			 * @return list<object>
			 */
			public function findEnabled(): array {
				return $this->webhooks;
			}//end findEnabled()
		};
		$objects = new class([self::row(['name' => 'Raadsinformatie', 'status' => 'idle']), self::row(['name' => 'Oud', 'status' => 'disabled']), ['name' => 'Fout', 'status' => 'error']]) {
			/**
			 * The filters asked for.
			 *
			 * @var array<string, mixed>
			 */
			public array $asked = [];

			/**
			 * Hold the rows.
			 *
			 * @param list<mixed> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}//end __construct()

			/**
			 * Find objects.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 *
			 * @return array<string, mixed>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->asked = $config['filters'] + ['rbac' => $_rbac];
				return ['results' => $this->rows];
			}//end findAll()
		};
		$reach = $this->reach(services: [
			'OCA\OpenRegister\Db\WebhookMapper' => $webhooks,
			'OCA\OpenRegister\Service\ObjectService' => $objects,
		]);

		self::assertSame(['webhooks' => ['Zaaksysteem', 'Archief'], 'synchronisations' => ['Raadsinformatie', 'Fout']], $reach->list());
		self::assertSame(['register' => 'integriq', 'schema' => 'synchronization', 'rbac' => false], $objects->asked);
	}//end testEnabledWebhooksAndSynchronisationsAreNamed()

	/**
	 * Without OpenRegister's mapper or object service both lists are empty, not an error.
	 *
	 * @return void
	 */
	public function testAbsentAppsListNothing(): void {
		self::assertSame(['webhooks' => [], 'synchronisations' => []], $this->reach(services: [])->list());
	}//end testAbsentAppsListNothing()
}//end class
