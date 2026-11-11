<?php

/**
 * Unit tests for the HandRecordsToSystem repair step.
 *
 * Pins that every record an owner other than the system holds in the
 * system-owned schemas is handed over through OpenRegister's release call
 * inside a system operation, that unowned records and unconfigured schemas
 * are skipped, and that an OpenRegister without the call changes nothing.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Repair;

use OCA\OpenCatalogi\Repair\HandRecordsToSystem;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

if (class_exists('OCA\\OpenRegister\\Service\\Rbac\\OwnedRecord') === false) {
	require_once __DIR__ . '/../../Stubs/OpenRegister/Service/Rbac/OwnedRecord.php';
}

final class HandRecordsToSystemTest extends TestCase {

	/**
	 * Whether the fake OpenRegister ran a system operation.
	 */
	private bool $inSystem = false;

	/**
	 * The uuids handed to the system, with whether the call ran inside a system operation.
	 *
	 * @var array<string, bool>
	 */
	private array $released = [];

	private function step(object $ownership, array $config): HandRecordsToSystem {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ($config[$key] ?? '')
		);

		$test = $this;
		$objects = new class ($test) {
			public function __construct(private readonly HandRecordsToSystemTest $test) {
			}

			public function runAsSystem(callable $operation): mixed {
				return $this->test->inSystem(operation: $operation);
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($query['@self']['schema'] !== 'dep') {
					return ['results' => []];
				}

				return ['results' => [['@self' => ['id' => 'u-editor']], ['@self' => ['id' => 'u-none']], ['id' => 'u-system']]];
			}

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, bool $_audit = true): object {
				$owner = ['u-editor' => 'editor', 'u-none' => null, 'u-system' => '__system__'][$id];
				return new class ($id, $owner) {
					public function __construct(public readonly string $uuid, private readonly ?string $owner) {
					}

					public function getOwner(): ?string {
						return $this->owner;
					}
				};
			}
		};

		$mapper = new class {
			public function find(string $id): string {
				return 'entity-' . $id;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			'OCA\OpenRegister\Service\ObjectService' => $objects,
			'OCA\OpenRegister\Service\Rbac\ObjectOwnershipServiceInterface' => $ownership,
			default => $mapper,
		});

		return new HandRecordsToSystem(appManager: $apps, config: $appConfig, container: $container);
	}//end step()

	/**
	 * Run an operation as the fake OpenRegister's system operation.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What it returns.
	 */
	public function inSystem(callable $operation): mixed {
		$this->inSystem = true;
		try {
			return $operation();
		} finally {
			$this->inSystem = false;
		}
	}//end inSystem()

	/**
	 * Record a release call.
	 *
	 * @param object $record The record.
	 *
	 * @return array The outcome.
	 */
	public function release(object $record): array {
		$this->released[$record->object->uuid] = $this->inSystem;
		return ['changed' => $record->object->getOwner() !== '__system__'];
	}//end release()

	private function ownership(): object {
		return new class ($this) {
			public function __construct(private readonly HandRecordsToSystemTest $test) {
			}

			public function releaseToSystem(object $record): array {
				return $this->test->release(record: $record);
			}
		};
	}//end ownership()

	public function testOwnedRecordsAreHandedToTheSystemInsideASystemOperation(): void {
		$step = $this->step(ownership: $this->ownership(), config: ['publication_register' => 'reg', 'depublication_schema' => 'dep', 'woo_request_schema' => 'woo']);
		$info = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$info): void {
			$info[] = $message;
		});

		$step->run($output);

		$this->assertSame(['u-editor' => true, 'u-system' => true], $this->released);
		$this->assertSame(['Handed 1 record(s) to the system.'], $info);
	}//end testOwnedRecordsAreHandedToTheSystemInsideASystemOperation()

	public function testAnOpenRegisterWithoutTheReleaseCallChangesNothing(): void {
		$step = $this->step(ownership: new class {
		}, config: ['publication_register' => 'reg', 'depublication_schema' => 'dep']);
		$info = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$info): void {
			$info[] = $message;
		});

		$step->run($output);

		$this->assertSame([], $this->released);
		$this->assertStringContainsString('cannot hand records to the system yet', $info[0]);
	}//end testAnOpenRegisterWithoutTheReleaseCallChangesNothing()
}//end class
