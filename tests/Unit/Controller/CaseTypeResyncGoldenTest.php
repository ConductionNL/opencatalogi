<?php

/**
 * Golden test for `ServiceCatalogueController::previewResync()` and `applyResync()`.
 *
 * Runs both endpoints over a scripted store and case type service and pins
 * every answer: an unreadable catalogue, a missing register configuration, a
 * definition that cannot be found, never imported or imported without a
 * source id, an unreachable source, and a successful preview and apply
 * (with and without a usable `accepted` list), including what was diffed,
 * applied and saved.
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
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
 *
 * @covers \OCA\OpenCatalogi\Controller\ServiceCatalogueController
 * @covers \OCA\OpenCatalogi\Controller\ResolvesRegisterConfiguration
 * @covers \OCA\OpenCatalogi\Controller\ReadsOpenRegisterResults
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Service\CaseTypeCatalogueService;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for the case type resynchronisation endpoints.
 */
class CaseTypeResyncGoldenTest extends TestCase {

	/**
	 * Scenario per case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$imported = ['identifier' => 'vergunning', 'source' => ['sourceId' => 'vng', 'externalId' => 'ext-1']];
		$cases = [
			'unreadable catalogue' => ['unreadable' => true],
			'register not configured' => ['register' => ''],
			'definition not found' => ['stored' => null],
			'never imported' => ['stored' => ['identifier' => 'local-only']],
			'source is not a list' => ['stored' => ['identifier' => 'x', 'source' => 'vng']],
			'source without id' => ['stored' => ['identifier' => 'x', 'source' => ['sourceId' => '', 'externalId' => 'e']]],
			'unreachable source' => ['stored' => $imported, 'unreachable' => true],
			'imported, accepted list' => ['stored' => $imported, 'accepted' => ['title', 3]],
			'imported, accepted not a list' => ['stored' => $imported, 'accepted' => 'title'],
			'imported without external id' => ['stored' => ['identifier' => 'y', 'source' => ['sourceId' => 'vng']], 'accepted' => []],
			'stored as an entity envelope' => ['stored' => ['id' => 'env-1', 'object' => $imported], 'accepted' => ['title']],
		];
		$out = [];
		foreach (['previewResync', 'applyResync'] as $endpoint) {
			foreach ($cases as $name => $case) {
				$out[$endpoint . ', ' . $name] = [$case + ['endpoint' => $endpoint]];
			}
		}

		return $out;
	}//end cases()

	/**
	 * Each endpoint answers, diffs, applies and saves exactly as pinned.
	 *
	 * @param array<string, mixed> $case The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testResyncMatchesTheRecording(array $case): void {
		$log = [];
		$controller = $this->controller($case, $log);
		$response = $controller->{$case['endpoint']}('vergunning');
		$data = $response->getData();
		if (($case['register'] ?? '42') === '') {
			// The detail names the missing-config exception, whose message differs between OpenRegister versions.
			unset($data['detail']);
		}

		$actual = json_encode(
			['status' => $response->getStatus(), 'data' => $data, 'log' => $log],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		$this->assertSame($this->expected($case), $actual);

	}//end testResyncMatchesTheRecording()

	/**
	 * The expected answer, written out per scenario.
	 *
	 * @param array<string, mixed> $case The scenario.
	 *
	 * @return string
	 */
	private function expected(array $case): string {
		$apply = ($case['endpoint'] === 'applyResync');
		$stored = ($case['stored'] ?? []);
		if (isset($stored['object']) === true) {
			$stored = $stored['object'] + ['id' => $stored['id']];
		}

		$log = [];
		if (($case['unreadable'] ?? false) === true) {
			$out = [503, ['error' => 'catalogue-unreadable']];
		} elseif (($case['register'] ?? '42') === '') {
			$out = [503, ['error' => 'register_not_configured']];
		} elseif ($case['stored'] === null) {
			$out = [503, ['error' => 'register_not_configured', 'detail' => 'no such object: vergunning']];
		} elseif (is_array($stored['source'] ?? null) === false || ($stored['source']['sourceId'] ?? '') === '') {
			$data = ['error' => 'not-imported'];
			if ($apply === false) {
				$data['message'] = 'This definition was not imported, so there is no source to compare it with.';
			}

			$out = [400, $data];
		} else {
			$log[] = ['diff' => [$stored, 'vng', (string)($stored['source']['externalId'] ?? '')]];
			if (($case['unreachable'] ?? false) === true) {
				$out = [502, [
					'error' => 'external-catalogue-unreachable',
					'message' => 'The external catalogue could not be reached, so nothing is known about it right now.',
					'detail' => 'timed out',
				]];
			} elseif ($apply === false) {
				$out = [200, ['changed' => ['title']]];
			} else {
				$accepted = (is_array($case['accepted']) === true ? array_values(array_map('strval', $case['accepted'])) : []);
				$log[] = ['apply' => [$stored, ['changed' => ['title']], $accepted]];
				$log[] = ['save' => [$stored + ['title' => 'New'], ['42', '42', 'vergunning']]];
				$out = [200, $stored + ['title' => 'New']];
			}
		}//end if

		return json_encode(['status' => $out[0], 'data' => $out[1], 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}//end expected()

	/**
	 * The controller over a scripted store.
	 *
	 * @param array<string, mixed> $case The scenario.
	 * @param array<int, mixed> $log Every diff, apply and save, collected.
	 *
	 * @return ServiceCatalogueController
	 */
	private function controller(array $case, array &$log): ServiceCatalogueController {
		$register = ($case['register'] ?? '42');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'cors_allowed_origins' ? '*' : ($key === 'service_catalogue_register' ? $register : '42'))
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($config) {
				if ($id === IAppConfig::class) {
					return $config;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'accepted' ? ($case['accepted'] ?? $default) : $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$store = new class(($case['stored'] ?? null), $log) {
			/**
			 * @param array<string, mixed>|null $stored The stored definition, or null when missing.
			 * @param array<int, mixed> $log The shared log.
			 */
			public function __construct(
				private ?array $stored,
				private array &$log,
			) {
			}

			public function find(string $id, string $register, string $schema): array {
				if ($this->stored === null) {
					throw new \RuntimeException('no such object: ' . $id);
				}

				return $this->stored;
			}

			/**
			 * @param array<string, mixed> $object The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
				$this->log[] = ['save' => [$object, [$register, $schema, $uuid]]];
				return $object;
			}
		};

		$catalogue = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();
		if (($case['unreadable'] ?? false) === true) {
			$catalogue->method('getObjectService')->willThrowException(new CatalogueUnreadableException('down'));
		} else {
			$catalogue->method('getObjectService')->willReturn($store);
		}

		$caseTypes = $this->getMockBuilder(CaseTypeCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['diffAgainstSource', 'applyResync'])
			->getMock();
		$caseTypes->method('diffAgainstSource')->willReturnCallback(
			static function (array $local, string $sourceId, string $externalId) use (&$log, $case): array {
				$log[] = ['diff' => [$local, $sourceId, $externalId]];
				if (($case['unreachable'] ?? false) === true) {
					throw new ExternalCatalogueUnreachableException('timed out');
				}

				return ['changed' => ['title']];
			}
		);
		$caseTypes->method('applyResync')->willReturnCallback(
			static function (array $local, array $diff, array $accepted) use (&$log): array {
				$log[] = ['apply' => [$local, $diff, $accepted]];
				return $local + ['title' => 'New'];
			}
		);

		return new ServiceCatalogueController(
			'opencatalogi',
			$request,
			$config,
			$container,
			$l10n,
			$catalogue,
			$caseTypes
		);

	}//end controller()
}//end class
