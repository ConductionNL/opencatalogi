<?php

/**
 * Golden test for how WithheldDocuments::record resolves grounds.
 *
 * Every world is recorded once and replayed from the fixture: the answer,
 * what was handed to the store, and how often dossiq's list was asked.
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

require_once __DIR__ . '/Fixtures/DossiqWooRefusalGrounds.php';

use OCA\OpenCatalogi\Service\Woo\WithheldDocuments;
use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for WithheldDocuments::record.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\WithheldDocuments
 */
class WithheldGroundsResolveGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/withheld-grounds-resolve-golden.json';

	/**
	 * What happened, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded worlds: the entries, and whether dossiq answers with a list.
	 *
	 * @return array<string, array{0: list<mixed>, 1: string}>
	 */
	public static function worlds(): array {
		$entries = [
			['position' => 1, 'grounds' => ['5.1.2.e', ' 5.1.1.c ']],
			['position' => '2', 'grounds' => ['5.1.2.e'], 'title' => 'dropped'],
			['position' => 3, 'grounds' => ['nope', '5.1.2.e']],
			['position' => 1, 'grounds' => ['5.1.1.c']],
			['position' => 4, 'grounds' => ['5.1.2.e', 'nope']],
			['position' => 0, 'grounds' => ['5.1.2.e']],
			['position' => 5, 'grounds' => []],
			['position' => 6, 'grounds' => ['', 7, 'bare']],
			'not an entry',
			['grounds' => ['5.1.2.e']],
			['position' => 7, 'grounds' => ['5.1.2.e']],
			['position' => 3, 'grounds' => ['5.1.2.e']],
		];

		$cases = [];
		foreach (['list', 'per code'] as $dossiq) {
			$cases[$dossiq . ', mixed entries'] = [$entries, $dossiq];
			$cases[$dossiq . ', no entries'] = [[], $dossiq];
			$cases[$dossiq . ', one entry'] = [[['position' => 9, 'grounds' => ['bare']]], $dossiq];
		}

		return $cases;

	}//end worlds()

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param list<mixed> $entries The entries.
	 * @param string      $dossiq  `list` or `per code`.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testRecordMatchesTheRecording(array $entries, string $dossiq): void {
		$this->log = [];
		$answer = $this->service(dossiq: $dossiq)->record('pub-1', $entries, 'dossiq');
		$actual = ['answer' => $answer, 'log' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testRecordMatchesTheRecording()

	/**
	 * The service of a world.
	 *
	 * @param string $dossiq `list` or `per code`.
	 *
	 * @return WithheldDocuments
	 */
	private function service(string $dossiq): WithheldDocuments {
		$store = $this->createMock(WithheldDocumentStore::class);
		$store->method('publicationExists')->willReturn(true);
		$store->method('replace')->willReturnCallback(
			function (string $publicationId, array $accepted, string $source): void {
				$this->log[] = ['replace', $publicationId, $accepted, $source];
			}
		);

		$grounds = [
			['code' => '5.1.2.e', 'article' => '5.1', 'label' => 'Privacy'],
			['code' => '5.1.2.e', 'article' => 'later duplicate', 'label' => 'ignored'],
			'not a ground',
			['article' => 'no code'],
			['code' => '5.1.1.c', 'label' => 'No article'],
			['code' => 'bare'],
		];
		$log = &$this->log;
		$double = ($dossiq === 'list')
			? new class($grounds, $log) extends \OCA\Dossiq\Woo\WooRefusalGrounds {
				public function __construct(private array $grounds, private array &$log) {
				}

				public function listWithRetired(): array {
					$this->log[] = ['listWithRetired'];
					return $this->grounds;
				}

				public function byCode(string $code): ?array {
					$this->log[] = ['byCode', $code];
					return null;
				}
			}
			: new class($grounds, $log) {
				public function __construct(private array $grounds, private array &$log) {
				}

				public function byCode(string $code): ?array {
					$this->log[] = ['byCode', $code];
					foreach ($this->grounds as $ground) {
						if (is_array($ground) === true && ($ground['code'] ?? null) === $code) {
							return $ground;
						}
					}

					return null;
				}
			};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($double);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new WithheldDocuments($store, $container, $appManager);

	}//end service()
}//end class
