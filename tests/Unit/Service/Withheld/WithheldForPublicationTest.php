<?php

/**
 * Pins when WithheldFromPublication::addForPublication() adds the withheld
 * list to a read without a catalogue, and when it leaves the key out.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 */

declare(strict_types=1);

namespace Unit\Service\Withheld;

use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublication;
use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublicationStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for WithheldFromPublication::addForPublication().
 */
class WithheldForPublicationTest extends TestCase {

	private const STORED = [['id' => 'w-1', 'position' => 2, 'grounds' => [['code' => '5.1.2.e']]]];

	/**
	 * Every read and whether it carries the list, what the store was asked and what was logged.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: bool, 3: list<array{0: string, 1: string}>, 4: int}>
	 */
	public static function cases(): array {
		$read = ['id' => 'pub-1', '@self' => ['register' => '1', 'schema' => '2']];
		$on = ['showWithheld' => true];
		$off = ['showWithheld' => false];

		return [
			'every holding catalogue opted in' => [$read, [$on, $on], true, [['1', '2']], 0],
			'one holding catalogue did not opt in' => [$read, [$on, $off], false, [['1', '2']], 0],
			'the first did not opt in' => [$read, [$off, $on], false, [['1', '2']], 0],
			'opt-in as text is no opt-in' => [$read, [['showWithheld' => 'true']], false, [['1', '2']], 0],
			'no holding catalogue' => [$read, [], false, [['1', '2']], 0],
			'the catalogues cannot be read' => [$read, 'throw', false, [['1', '2']], 1],
			'the id under @self' => [['@self' => ['id' => 'pub-9', 'register' => 1, 'schema' => 2]], [$on], true, [['1', '2']], 0],
			'extended register and schema' => [['id' => 'pub-1', '@self' => ['register' => ['id' => 5], 'schema' => ['id' => '6']]], [$on], true, [['5', '6']], 0],
			'no id' => [['@self' => ['register' => '1', 'schema' => '2']], [$on], false, [], 0],
			'no register' => [['id' => 'pub-1', '@self' => ['schema' => '2']], [$on], false, [], 0],
			'no schema' => [['id' => 'pub-1', '@self' => ['register' => '1']], [$on], false, [], 0],
			'a register that is no id' => [['id' => 'pub-1', '@self' => ['register' => 1.5, 'schema' => '2']], [$on], false, [], 0],
			'no @self' => [['id' => 'pub-1'], [$on], false, [], 0],
		];

	}//end cases()

	/**
	 * The list is added only when every catalogue holding the publication opted in.
	 *
	 * @param array<string, mixed> $read The rendered publication.
	 * @param mixed $catalogs What the store answers, or 'throw'.
	 * @param bool $listed Whether the answer carries the list.
	 * @param list<array{0: string, 1: string}> $asked The register/schema pairs the store was asked for.
	 * @param int $warnings How many warnings were logged.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheListIsAddedOnlyWhenEveryHoldingCatalogueOptedIn(array $read, mixed $catalogs, bool $listed, array $asked, int $warnings): void {
		$calls = [];
		$store = $this->createMock(WithheldFromPublicationStore::class);
		$store->method('catalogsHolding')->willReturnCallback(
			static function (string $register, string $schema) use (&$calls, $catalogs): array {
				$calls[] = [$register, $schema];
				if ($catalogs === 'throw') {
					throw new RuntimeException('register down');
				}

				return $catalogs;
			}
		);
		$store->method('batchAssessments')->willReturn([]);
		$store->method('storedEntries')->willReturn(self::STORED);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$answer = (new WithheldFromPublication(store: $store, logger: $logger))->addForPublication(response: $read);

		$expected = $read;
		if ($listed === true) {
			$expected['withheld'] = [['position' => 2, 'grounds' => [['code' => '5.1.2.e']], 'source' => '', 'recordedAt' => '']];
		}

		$this->assertSame(array_keys($expected), array_keys($answer));
		$this->assertSame($listed, isset($answer['withheld']));
		$this->assertSame($asked, $calls);
		$this->assertCount($warnings, $logged);
		if ($warnings === 1) {
			$this->assertSame(['WithheldFromPublication: the catalogues could not be read', ['exception' => 'register down']], $logged[0]);
		}
	}//end testTheListIsAddedOnlyWhenEveryHoldingCatalogueOptedIn()
}//end class
