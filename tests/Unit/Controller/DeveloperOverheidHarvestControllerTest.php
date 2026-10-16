<?php

/**
 * Tests for DeveloperOverheidHarvestController: every method is admin-gated
 * with no CSRF opt-out, every endpoint reaches the service under harvest key
 * `developer-overheid`, a refused precondition answers 409 with a static
 * localised message and its slug, and any other failure is logged and
 * answers a static 500. No response carries exception text.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Controller\DeveloperOverheidHarvestController;
use OCA\OpenCatalogi\Exception\HarvestNotReadyException;
use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Controller\DeveloperOverheidHarvestController
 */
class DeveloperOverheidHarvestControllerTest extends TestCase {

	/**
	 * The five endpoints, by controller method.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const ROUTES = [
		'status' => ['GET', '/api/settings/developer-overheid-harvest'],
		'setup' => ['POST', '/api/settings/developer-overheid-harvest/setup'],
		'enable' => ['POST', '/api/settings/developer-overheid-harvest/enable'],
		'disable' => ['POST', '/api/settings/developer-overheid-harvest/disable'],
		'run' => ['POST', '/api/settings/developer-overheid-harvest/run'],
	];

	/**
	 * The harvest service double.
	 *
	 * @var PubliccodeHarvestService&MockObject
	 */
	private PubliccodeHarvestService&MockObject $harvest;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var DeveloperOverheidHarvestController
	 */
	private DeveloperOverheidHarvestController $controller;

	/**
	 * Build the controller over a service double and a marking translator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harvest = $this->getMockBuilder(PubliccodeHarvestService::class)
			->disableOriginalConstructor()
			->onlyMethods(['status', 'setUp', 'setEnabled', 'runNow'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[nl] ' . $text);

		$this->controller = new DeveloperOverheidHarvestController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			$this->harvest,
			$l10n,
			$this->logger
		);
	}//end setUp()

	/**
	 * Every public endpoint is admin-gated through the OpenCatalogi settings
	 * section and carries no CSRF or auth opt-out, as attribute or annotation.
	 *
	 * @return void
	 */
	public function testEveryMethodIsAdminGatedWithoutACsrfOptOut(): void {
		$class = new ReflectionClass(DeveloperOverheidHarvestController::class);
		$endpoints = array_values(array_filter(
			array_map(static fn (ReflectionMethod $m): string => $m->getName(), $class->getMethods(ReflectionMethod::IS_PUBLIC)),
			static fn (string $name): bool => $name !== '__construct' && method_exists(\OCP\AppFramework\Controller::class, $name) === false
		));
		$this->assertSame(array_keys(self::ROUTES), $endpoints);

		foreach ($endpoints as $name) {
			$method = $class->getMethod($name);
			$gates = $method->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $gates, $name);
			$this->assertSame(['settings' => OpenCatalogiAdmin::class], $gates[0]->getArguments(), $name);

			foreach ($method->getAttributes() as $attribute) {
				$this->assertNotContains(
					(new ReflectionClass($attribute->getName()))->getShortName(),
					['NoCSRFRequired', 'NoAdminRequired', 'PublicPage'],
					$name
				);
			}

			$doc = (string)$method->getDocComment();
			foreach (['@NoCSRFRequired', '@NoAdminRequired', '@PublicPage'] as $annotation) {
				$this->assertStringNotContainsString($annotation, $doc, $name);
			}
		}
	}//end testEveryMethodIsAdminGatedWithoutACsrfOptOut()

	/**
	 * The five routes point at this controller with the documented verbs.
	 *
	 * @return void
	 */
	public function testTheFiveRoutesPointAtThisController(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$ours = [];
		foreach ($routes as $route) {
			if (str_starts_with($route['name'], 'developerOverheidHarvest#') === true) {
				$ours[substr($route['name'], strlen('developerOverheidHarvest#'))] = [$route['verb'], $route['url']];
			}
		}

		$this->assertSame(self::ROUTES, $ours);
	}//end testTheFiveRoutesPointAtThisController()

	/**
	 * Every endpoint reaches the service under the developer.overheid.nl key
	 * and answers its result flat.
	 *
	 * @return void
	 */
	public function testEveryEndpointAnswersTheServiceResultFlat(): void {
		$this->harvest->expects($this->once())->method('status')->with('developer-overheid')->willReturn(['integriq' => true, 'synchronizations' => ['expected' => 1, 'present' => 1]]);
		$this->harvest->expects($this->once())->method('setUp')->with('developer-overheid')->willReturn(['created' => 1, 'updated' => 0]);
		$this->harvest->expects($this->once())->method('runNow')->with('developer-overheid')->willReturn(['uuid' => 'run-1', 'status' => 'queued']);
		$asked = [];
		$this->harvest->method('setEnabled')->willReturnCallback(
			static function (bool $enabled, string $harvest) use (&$asked): array {
				$asked[] = [$enabled, $harvest];
				return ['enabled' => $enabled];
			}
		);

		$expected = [
			'status' => ['integriq' => true, 'synchronizations' => ['expected' => 1, 'present' => 1]],
			'setup' => ['created' => 1, 'updated' => 0],
			'enable' => ['enabled' => true],
			'disable' => ['enabled' => false],
			'run' => ['uuid' => 'run-1', 'status' => 'queued'],
		];
		foreach ($expected as $endpoint => $data) {
			$response = $this->controller->$endpoint();
			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $endpoint);
			$this->assertSame($data, $response->getData(), $endpoint);
		}

		$this->assertSame([[true, 'developer-overheid'], [false, 'developer-overheid']], $asked);
	}//end testEveryEndpointAnswersTheServiceResultFlat()

	/**
	 * Every refusal is a 409 with a static localised message and its slug.
	 *
	 * @return void
	 */
	public function testARefusalIsAConflictWithAStaticMessageAndItsSlug(): void {
		$slugs = ['needs-integriq', 'needs-openregister', 'flow-not-imported', 'not-set-up', 'switched-off', 'some-future-reason'];
		$this->harvest->method('setUp')->willReturnCallback(
			static function () use (&$slug): array {
				throw new HarvestNotReadyException(message: 'internal detail ' . $slug . ' at /var/www/secret', error: $slug);
			}
		);

		$messages = [];
		foreach ($slugs as $slug) {
			$response = $this->controller->setup();
			$data = $response->getData();
			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus(), $slug);
			$this->assertSame(['message', 'error'], array_keys($data), $slug);
			$this->assertSame($slug, $data['error']);
			$this->assertStringStartsWith('[nl] ', $data['message'], 'The message is localised: ' . $slug);
			$this->assertStringNotContainsString('internal detail', $data['message'], 'No exception text: ' . $slug);
			$messages[] = $data['message'];
		}

		$this->assertCount(count($slugs), array_unique($messages), 'Each reason has its own message.');
	}//end testARefusalIsAConflictWithAStaticMessageAndItsSlug()

	/**
	 * Running before switching on is refused with `switched-off`.
	 *
	 * @return void
	 */
	public function testRunningBeforeSwitchingOnIsRefused(): void {
		$this->harvest->method('runNow')->willThrowException(
			new HarvestNotReadyException(message: 'Switch the harvest on before you run it.', error: HarvestNotReadyException::SWITCHED_OFF)
		);

		$response = $this->controller->run();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['message' => '[nl] Switch the harvest on before you run it.', 'error' => 'switched-off'],
			$response->getData()
		);
	}//end testRunningBeforeSwitchingOnIsRefused()

	/**
	 * Any other failure is logged with the exception and answers a static 500.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsLoggedAndAnswersAStatic500(): void {
		$failure = new RuntimeException('The synchronization file /var/www/html/custom_apps/opencatalogi/lib/Settings/x.json is not valid.');
		foreach (['status', 'setUp', 'setEnabled', 'runNow'] as $method) {
			$this->harvest->method($method)->willThrowException($failure);
		}

		$this->logger->expects($this->exactly(5))->method('error')->with(
			$this->stringContains('developer.overheid.nl harvest'),
			$this->callback(static fn (array $context): bool => ($context['exception'] ?? null) === $failure)
		);

		foreach (array_keys(self::ROUTES) as $endpoint) {
			$response = $this->controller->$endpoint();
			$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus(), $endpoint);
			$this->assertSame(
				['message' => '[nl] The developer.overheid.nl harvest could not do that. The server log has the details.', 'error' => 'harvest-failed'],
				$response->getData(),
				$endpoint
			);
			$this->assertStringNotContainsString('/var/www', (string)json_encode($response->getData()), $endpoint);
		}
	}//end testAnyOtherFailureIsLoggedAndAnswersAStatic500()
}//end class
