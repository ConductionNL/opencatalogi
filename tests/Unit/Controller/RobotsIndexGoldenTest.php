<?php

/**
 * Golden test for RobotsController::index().
 *
 * Every case builds robots.txt over a recording ObjectService double and compares
 * the response and the catalogue search to the recording in
 * fixtures/robots-index-golden.json.
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Controller\RobotsController;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for RobotsController::index().
 *
 * @covers \OCA\OpenCatalogi\Controller\RobotsController
 * @uses   \OCA\OpenCatalogi\Http\TextResponse
 */
class RobotsIndexGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/robots-index-golden.json';

	private const CONFIGURED = ['configuration' => ['catalog_register' => 'reg-1', 'catalog_schema' => 'sch-1']];

	/**
	 * The recorded cases: settings, the search answer, base URL, categories, installed.
	 *
	 * Each catalogue is [slug, object] with object null for one without getObject().
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$woo = ['hasWooSitemap' => true];
		$two = ['c1' => 'a.xml', 'c2' => 'b.xml'];

		return [
			'no settings' => [['settings' => []]],
			'no register' => [['settings' => ['configuration' => ['catalog_schema' => 's']]]],
			'no schema' => [['settings' => ['configuration' => ['catalog_register' => 'r']]]],
			'register null' => [['settings' => ['configuration' => ['catalog_register' => null, 'catalog_schema' => 's']]]],
			'configuration a string' => [['settings' => ['configuration' => 'x']]],
			'no results key' => [['result' => []]],
			'no catalogues' => [['result' => ['results' => []]]],
			'one catalogue, two categories' => [['catalogs' => [['woo', $woo]], 'categories' => $two]],
			'no categories' => [['catalogs' => [['woo', $woo]], 'categories' => []]],
			'trailing slashes on the base url' => [['catalogs' => [['woo', $woo]], 'base' => 'https://example.org//']],
			'empty base url' => [['catalogs' => [['woo', $woo]], 'base' => '']],
			'slug null' => [['catalogs' => [[null, $woo], ['ok', $woo]]]],
			'slug empty' => [['catalogs' => [['', $woo]]]],
			'slug an int' => [['catalogs' => [[7, $woo]]]],
			'no getObject' => [['catalogs' => [['plain', null], ['ok', $woo]]]],
			'hasWooSitemap false' => [['catalogs' => [['off', ['hasWooSitemap' => false]]]]],
			'hasWooSitemap as text' => [['catalogs' => [['text', ['hasWooSitemap' => 'true']]]]],
			'hasWooSitemap as one' => [['catalogs' => [['one', ['hasWooSitemap' => 1]]]]],
			'hasWooSitemap missing' => [['catalogs' => [['none', []]]]],
			'object as an object' => [['catalogs' => [['obj', (object)$woo]]]],
			'three catalogues' => [['catalogs' => [['a', $woo], ['b', ['hasWooSitemap' => false]], ['c', $woo]], 'categories' => $two]],
			'openregister missing' => [['installed' => false]],
			'openregister missing, no settings' => [['installed' => false, 'settings' => []]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testIndexMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$searched = [];
		$result = ($case['result'] ?? ['results' => array_map(static fn (array $c): object => self::catalog(slug: $c[0], data: $c[1]), ($case['catalogs'] ?? []))]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (...$args) use (&$searched, $result): array {
				$searched[] = self::passedArguments(method: 'searchObjectsPaginated', args: $args);
				return $result;
			}
		);

		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('isInstalled')->willReturn($case['installed'] ?? true);
		$lookup->method('objectService')->willReturn($objects);

		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getSettings')->willReturn($case['settings'] ?? self::CONFIGURED);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn($case['base'] ?? 'https://example.com');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);
		$categories = $this->createMock(InformationCategoryRegistryInterface::class);
		$categories->method('sitemapFiles')->willReturn($case['categories'] ?? ['c1' => 'a.xml']);

		$controller = new RobotsController('opencatalogi', $this->createMock(IRequest::class), $settings, $lookup, $urls, $l10n, $categories);

		try {
			$response = $controller->index();
			$answer = ['status' => $response->getStatus(), 'text' => $response->render(), 'type' => ($response->getHeaders()['Content-Type'] ?? null)];
		} catch (\Throwable $e) {
			$answer = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = ['answer' => $answer, 'searched' => $searched];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testIndexMatchesTheRecording()

	/**
	 * A catalogue double with a slug, and getObject() unless data is null.
	 *
	 * @param mixed $slug The slug.
	 * @param mixed $data The object data, or null for a catalogue without getObject().
	 *
	 * @return object The catalogue.
	 */
	private static function catalog(mixed $slug, mixed $data): object {
		if ($data === null) {
			return new class($slug) {
				public function __construct(private mixed $slug) {
				}

				public function getSlug(): mixed {
					return $this->slug;
				}
			};
		}

		return new class($slug, $data) {
			public function __construct(private mixed $slug, private mixed $data) {
			}

			public function getSlug(): mixed {
				return $this->slug;
			}

			public function getObject(): mixed {
				return $this->data;
			}
		};

	}//end catalog()

	/**
	 * The arguments of an ObjectService call by parameter name, without those left at their default.
	 *
	 * A mock receives every parameter of the loaded ObjectService, so a positional list
	 * would record that class's signature: the test stub's locally and OpenRegister's
	 * own in CI. Named and without defaults, the record is what the controller passed.
	 *
	 * @param string            $method The ObjectService method.
	 * @param array<int, mixed> $args   The arguments the mock received.
	 *
	 * @return array<string, mixed> The passed arguments by name.
	 */
	private static function passedArguments(string $method, array $args): array {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectService::class, $method))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === false) {
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true && $args[$position] === $parameter->getDefaultValue()) {
				continue;
			}

			$named[$parameter->getName()] = $args[$position];
		}

		return $named;

	}//end passedArguments()
}//end class
