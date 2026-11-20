<?php

/**
 * Every existing publication gets audience public (REQ-PSR-001).
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\SetPublicationAudiencePublic;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Repair\SetPublicationAudiencePublic
 */
final class AudienceMigrationTest extends TestCase {

	/**
	 * The stored publications, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The saves: register, schema, uuid.
	 *
	 * @var array<int, array{0: mixed, 1: mixed, 2: mixed}>
	 */
	private array $saves = [];

	/**
	 * The ids whose save throws.
	 *
	 * @var array<int, string>
	 */
	private array $failing = [];

	/**
	 * The info lines.
	 *
	 * @var array<int, string>
	 */
	private array $info = [];

	/**
	 * A double of an OpenRegister class whose methods may be absent from the stub.
	 *
	 * @param class-string       $class   The class.
	 * @param array<int, string> $methods The methods.
	 *
	 * @return MockObject
	 */
	private function environmentAwareDouble(string $class, array $methods): MockObject {
		$existing = array_values(array_filter($methods, static fn (string $method): bool => method_exists($class, $method)));
		$absent = array_values(array_diff($methods, $existing));
		$builder = $this->getMockBuilder($class)->disableOriginalConstructor();
		if ($existing !== []) {
			$builder->onlyMethods($existing);
		}

		if ($absent !== []) {
			$builder->addMethods($absent);
		}

		return $builder->getMock();
	}//end environmentAwareDouble()

	/**
	 * Run the step over the store, with OpenRegister available or not.
	 *
	 * @param bool $available Whether OpenRegister answers.
	 *
	 * @return void
	 */
	private function runStep(bool $available=true): void {
		$objects = $this->environmentAwareDouble(\OCA\OpenRegister\Service\ObjectService::class, ['searchObjectsPaginated', 'saveObject']);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			fn (array $query = []): array => ($query['@self'] === ['register' => '7', 'schema' => '12'] && $query['_page'] === 1)
				? ['results' => array_values($this->store)]
				: ['results' => []]
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				if (in_array((string)$options->uuid, $this->failing, true) === true) {
					throw new RuntimeException('locked');
				}

				$this->saves[] = [$options->target->register, $options->target->schema, $options->uuid];
				$this->store[(string)$options->uuid] = $object;
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($available === true ? $objects : null);
		$settings->method('getSettings')->willReturn([
			'availableRegisters' => [
				['id' => '7', 'schemas' => [['id' => '12', 'slug' => 'publication'], ['id' => '13', 'slug' => 'catalog']]],
			],
		]);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->info[] = $line;
		});

		(new SetPublicationAudiencePublic(settings: $settings, logger: $this->createMock(LoggerInterface::class)))->run($output);
	}//end runStep()

	/**
	 * Every publication without an audience is saved with audience public, in its own register and schema.
	 *
	 * @return void
	 */
	public function testEveryExistingPublicationGetsAudiencePublic(): void {
		$this->store = [
			'a' => ['id' => 'a', 'title' => 'Raadsbesluit', '@self' => ['id' => 'a']],
			'b' => ['id' => 'b', 'title' => 'Wijkplan', 'audience' => ''],
		];

		$this->runStep();

		self::assertSame([['7', '12', 'a'], ['7', '12', 'b']], $this->saves);
		self::assertSame('public', $this->store['a']['audience']);
		self::assertSame('public', $this->store['b']['audience']);
		self::assertSame('Raadsbesluit', $this->store['a']['title']);
		self::assertArrayNotHasKey('@self', $this->store['a']);
		self::assertStringContainsString('2 publication(s) set to audience public, 0 already had an audience', $this->info[0]);
	}//end testEveryExistingPublicationGetsAudiencePublic()

	/**
	 * A publication that says who can read it is not touched, so a second run saves nothing.
	 *
	 * @return void
	 */
	public function testAPublicationWithAnAudienceIsLeftAsItIs(): void {
		$this->store = [
			'a' => ['id' => 'a', 'audience' => 'signedIn'],
			'b' => ['id' => 'b', 'audience' => 'public'],
		];

		$this->runStep();

		self::assertSame([], $this->saves);
		self::assertSame('signedIn', $this->store['a']['audience']);
		self::assertStringContainsString('0 publication(s) set to audience public, 2 already had an audience', $this->info[0]);
	}//end testAPublicationWithAnAudienceIsLeftAsItIs()

	/**
	 * A save that fails is counted and the run goes on.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCountedAndTheRunGoesOn(): void {
		$this->store = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];
		$this->failing = ['a'];

		$this->runStep();

		self::assertSame([['7', '12', 'b']], $this->saves);
		self::assertStringContainsString('1 could not be saved', $this->info[0]);
	}//end testAFailedSaveIsCountedAndTheRunGoesOn()

	/**
	 * Without OpenRegister the step does nothing and says so.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$this->store = ['a' => ['id' => 'a']];

		$this->runStep(available: false);

		self::assertSame([], $this->saves);
		self::assertStringContainsString('OpenRegister is not available', $this->info[0]);
	}//end testWithoutOpenRegisterNothingHappens()
}//end class
