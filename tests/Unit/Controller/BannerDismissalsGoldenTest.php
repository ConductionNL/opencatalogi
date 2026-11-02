<?php

/**
 * Golden test for the banner endpoints of the notice board controller.
 *
 * Every case runs banners() or dismissBanner() over recording doubles and
 * compares the answer, every config read and write and the dismissed ids the
 * banner service receives to the recording in fixtures/banner-dismissals-golden.json.
 * The controller is built by constructor reflection, so the same test drives any
 * wiring of the same doubles.
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

use OCA\OpenCatalogi\Contract\BannerServiceInterface;
use OCA\OpenCatalogi\Contract\NoticeBoardServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Controller\NoticeBoardController;
use OCA\OpenCatalogi\Controller\RegisterAccess;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Golden test for the banner endpoints.
 *
 * @covers \OCA\OpenCatalogi\Controller\NoticeBoardController
 * @covers \OCA\OpenCatalogi\Controller\BannerDismissals
 * @uses   \OCA\OpenCatalogi\Controller\ResolvesRegisterConfiguration
 * @uses   \OCA\OpenCatalogi\Controller\ReadsPublicationRegister
 * @uses   \OCA\OpenCatalogi\Service\OpenRegisterLookup
 * @uses   \OCA\OpenCatalogi\Controller\RegisterAccess
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */
class BannerDismissalsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/banner-dismissals-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		$stored = [
			'none' => null,
			'empty list' => '[]',
			'one id' => '["b-1"]',
			'numbers' => '[1, 2]',
			'an object' => '{"a": "b-1"}',
			'not json' => 'nope',
			'a string' => '"b-1"',
		];
		foreach ($stored as $label => $value) {
			$cases['banners, stored ' . $label] = [['call' => 'banners', 'stored' => $value]];
			foreach (['b-2' => 'b-2', 'padded' => '  b-1  ', 'empty' => '', 'blank' => '   '] as $param => $banner) {
				$cases['dismiss ' . $param . ', stored ' . $label] = [['call' => 'dismiss', 'stored' => $value, 'banner' => $banner]];
			}
		}

		$cases['dismiss without a banner parameter'] = [['call' => 'dismiss', 'stored' => '["b-1"]']];
		$cases['dismiss a number'] = [['call' => 'dismiss', 'stored' => '[]', 'banner' => 7]];
		$cases['banners anonymous'] = [['call' => 'banners', 'anonymous' => true, 'stored' => '["b-1"]']];
		$cases['dismiss anonymous'] = [['call' => 'dismiss', 'anonymous' => true, 'stored' => '[]', 'banner' => 'b-1']];
		$cases['banners unreadable'] = [['call' => 'banners', 'unreadable' => true, 'stored' => '["b-1"]']];
		$cases['banners unconfigured'] = [['call' => 'banners', 'unconfigured' => true, 'stored' => '["b-1"]']];
		$cases['banners for bob'] = [['call' => 'banners', 'user' => 'bob', 'stored' => '["b-9"]']];
		$cases['dismiss for bob'] = [['call' => 'dismiss', 'user' => 'bob', 'stored' => '["b-9"]', 'banner' => 'b-1']];
		return $cases;

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testBannersMatchTheRecording(array $case): void {
		$name = $this->dataName();
		$controller = $this->controller(case: $case);
		$response = match ($case['call']) {
			'banners' => $controller->banners(),
			'dismiss' => $controller->dismissBanner(),
		};

		$encoded = (string)json_encode(['status' => $response->getStatus(), 'data' => $response->getData(), 'log' => $this->log]);
		$actual = json_decode((string)preg_replace('/ passed in \S+ on line \d+/', ' passed', $encoded), true);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testBannersMatchTheRecording()

	/**
	 * The controller over recording doubles.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return NoticeBoardController The controller.
	 */
	private function controller(array $case): NoticeBoardController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'banner' && array_key_exists('banner', $case) === true ? $case['banner'] : $default)
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->log[] = ['read' => [$app, $key, $default]];
				if (str_starts_with($key, 'banner_dismissals_') === true) {
					return ($case['stored'] ?? $default);
				}

				if ($key === 'publication_register' || str_ends_with($key, '_schema') === true) {
					return (($case['unconfigured'] ?? false) === true ? '' : 'cfg-' . $key);
				}

				return $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->log[] = ['write' => [$app, $key, $value]];
				return true;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($case['user'] ?? 'alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($case['anonymous'] ?? false) === true ? null : $user);

		$banners = $this->createMock(BannerServiceInterface::class);
		$banners->method('forUser')->willReturnCallback(
			function (array $rows, array $dismissedIds): array {
				$this->log[] = ['forUser' => [$rows, $dismissedIds]];
				return [['id' => 'shown', 'from' => count($rows)]];
			}
		);

		$args = [];
		foreach ((new ReflectionClass(NoticeBoardController::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$typeName = ($type instanceof ReflectionNamedType ? $type->getName() : '');
			$args[] = match ($typeName) {
				'' => 'opencatalogi',
				IRequest::class => $request,
				IAppConfig::class => $config,
				IUserSession::class => $session,
				BannerServiceInterface::class => $banners,
				NoticeBoardServiceInterface::class => $this->createMock(NoticeBoardServiceInterface::class),
				RegisterAccess::class => new RegisterAccess(
					new OpenRegisterLookup($this->container(config: $config), $this->createMock(IAppManager::class)),
					$this->catalogue(case: $case)
				),
				'OCA\\OpenCatalogi\\Controller\\BannerDismissals' => new $typeName($config, $banners),
			};
		}

		return new NoticeBoardController(...$args);

	}//end controller()

	/**
	 * A container without the resolver, answering the app config.
	 *
	 * @param IAppConfig $config The app config.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(IAppConfig $config): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($config): object {
				if ($id === IAppConfig::class) {
					return $config;
				}

				throw new \RuntimeException('Not in the container: ' . $id);
			}
		);
		return $container;

	}//end container()

	/**
	 * The service catalogue: banner rows, or unreadable.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ServiceCatalogueServiceInterface The service catalogue.
	 */
	private function catalogue(array $case): ServiceCatalogueServiceInterface {
		$objects = new class {
			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed> The page.
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['results' => [['id' => 'b-1', 'query' => $query], ['id' => 'b-2']]];
			}
		};
		$catalogue = $this->createMock(ServiceCatalogueServiceInterface::class);
		if (($case['unreadable'] ?? false) === true) {
			$catalogue->method('getObjectService')->willThrowException(new CatalogueUnreadableException(message: 'unreadable'));
			return $catalogue;
		}

		$catalogue->method('getObjectService')->willReturn($objects);
		return $catalogue;

	}//end catalogue()
}//end class
