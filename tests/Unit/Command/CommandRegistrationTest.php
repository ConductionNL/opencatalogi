<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Command;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;

/**
 * Every concrete console command in lib/Command is registered in appinfo/info.xml,
 * so `occ list opencatalogi` shows it (REQ-IMC-001).
 *
 * @coversNothing
 *
 * @spec openspec/changes/archive/2026-11-20-integration-maintenance-commands/tasks.md#4.1
 */
class CommandRegistrationTest extends TestCase {

	/**
	 * The command classes info.xml registers.
	 *
	 * @return string[]
	 */
	private function registeredCommands(): array {
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse(condition: $xml, message: 'appinfo/info.xml must parse');

		$commands = [];
		foreach ($xml->commands->command ?? [] as $command) {
			$commands[] = trim((string) $command);
		}

		return $commands;
	}//end registeredCommands()

	/**
	 * The concrete Symfony commands that live in lib/Command.
	 *
	 * @return string[]
	 */
	private function concreteCommands(): array {
		$commands = [];
		$files = glob(pattern: __DIR__ . '/../../../lib/Command/*.php');
		if ($files === false) {
			$files = [];
		}

		foreach ($files as $file) {
			$class = 'OCA\\OpenCatalogi\\Command\\' . basename($file, '.php');
			if (class_exists($class) === false) {
				continue;
			}

			$reflection = new ReflectionClass($class);
			if ($reflection->isAbstract() === true || $reflection->isSubclassOf(Command::class) === false) {
				continue;
			}

			$commands[] = $class;
		}

		sort($commands);
		return $commands;
	}//end concreteCommands()

	/**
	 * The five maintenance commands of this change are registered.
	 *
	 * @return void
	 */
	public function testTheFiveMaintenanceCommandsAreRegistered(): void {
		$registered = $this->registeredCommands();
		foreach ([
			'DirectorySyncCommand',
			'DirectoryBroadcastCommand',
			'RetentionEvaluateCommand',
			'WooReadinessCommand',
			'CatalogCacheCommand',
		] as $short) {
			$this->assertContains(
				needle: 'OCA\\OpenCatalogi\\Command\\' . $short,
				haystack: $registered,
				message: $short . ' must be in info.xml'
			);
		}
	}//end testTheFiveMaintenanceCommandsAreRegistered()

	/**
	 * No concrete command is left out of info.xml, and info.xml names no class that does not exist.
	 *
	 * @return void
	 */
	public function testInfoXmlRegistersExactlyTheConcreteCommands(): void {
		$registered = $this->registeredCommands();
		sort($registered);

		$this->assertSame(expected: $this->concreteCommands(), actual: $registered);
	}//end testInfoXmlRegistersExactlyTheConcreteCommands()

	/**
	 * Each registered command names itself in the opencatalogi namespace.
	 *
	 * @return void
	 */
	public function testEveryRegisteredCommandIsInTheAppNamespace(): void {
		foreach ($this->registeredCommands() as $class) {
			$file   = (string) (new ReflectionClass(objectOrClass: $class))->getFileName();
			$source = (string) file_get_contents(filename: $file);
			$this->assertMatchesRegularExpression(
				pattern: "/setName\\(name: 'opencatalogi:[a-z:-]+'\\)/",
				string: $source,
				message: $class . ' must name itself opencatalogi:*'
			);
		}
	}//end testEveryRegisteredCommandIsInTheAppNamespace()
}//end class
