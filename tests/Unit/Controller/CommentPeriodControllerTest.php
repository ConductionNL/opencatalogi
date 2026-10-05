<?php

/**
 * Unit tests for CommentPeriodController.
 *
 * These assert on the RESPONSE, because a comment period that refuses inside the
 * service and answers 200 at the edge is the failure that matters here: a reader
 * who is told a period is open reacts to a draft nobody is obliged to read.
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

use OCA\OpenCatalogi\Controller\CommentPeriodController;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A duck-typed fake of the consumed OpenRegister ObjectService.
 */
class CommentPeriodFakeObjectService {

	/** @var array<string, array<string, mixed>> */
	public array $objects = [];

	public int $counter = 0;

	public function find(string $id, mixed ...$rest): array {
		if (isset($this->objects[$id]) === false) {
			throw new \RuntimeException('not found');
		}

		return $this->objects[$id];
	}//end find()

	public function saveObject(
		array $object,
		?array $extend = [],
		$register = null,
		$schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true
	): array {
		$this->counter++;
		$object['id'] = 'period-' . $this->counter;
		$this->objects[$object['id']] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Unit tests for CommentPeriodController.
 */
class CommentPeriodControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private CommentPeriodFakeObjectService $objects;
	private CommentPeriodController $controller;

	/**
	 * Build the controller over a faked register and a real period service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->objects = new CommentPeriodFakeObjectService();
		$this->controller = $this->build();

	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param bool   $registerAvailable Whether the container binds the ObjectService.
	 * @param string $configured        What the register and schema keys answer.
	 * @param bool   $signedIn          Whether a user is signed in.
	 *
	 * @return CommentPeriodController The controller.
	 */
	private function build(
		bool $registerAvailable = true,
		string $configured = '42',
		bool $signedIn = true
	): CommentPeriodController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($configured);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($config, $registerAvailable) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					if ($registerAvailable === false) {
						throw new \RuntimeException('not installed');
					}

					return $this->objects;
				}

				if ($id === IAppConfig::class) {
					return $config;
				}

				throw new \RuntimeException('not bound: ' . $id);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$userSession = $this->createMock(IUserSession::class);
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://example.org' . $path
		);

		$engine = $this->createMock(ContainerInterface::class);
		$engine->method('get')->willReturnCallback(
			static function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator') {
					return new \Unit\Service\Publication\FakeSlaCalculator();
				}

				return new \Unit\Service\Publication\FakeCalendarService();
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$periods = new CommentPeriodService(
			new TermRoll($engine, $appManager),
			$config,
			$urls
		);

		return new CommentPeriodController(
			'opencatalogi',
			$this->request,
			$container,
			$l10n,
			$userSession,
			$periods
		);

	}//end build()

	/**
	 * Make the request answer the given parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

	}//end params()

	/**
	 * The parameters of a period that opens.
	 *
	 * @param array<string, mixed> $overrides What to change.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function openParams(array $overrides = []): array {
		return array_merge(
			[
				'publication' => 'pub-1',
				'termDays' => 42,
				'legalRemedy' => 'zienswijze',
				'announcementUrl' => 'https://www.officielebekendmakingen.nl/stcrt-2026-1',
				'automaticWithdrawal' => false,
			],
			$overrides
		);
	}//end openParams()

	/**
	 * Opening a period stores it and answers 201 with the stored period.
	 *
	 * @return void
	 */
	public function testOpeningAPeriodAnswersWithTheStoredPeriod(): void {
		$this->params($this->openParams());

		$response = $this->controller->open();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('pub-1', $data['publication']);
		$this->assertSame(42, $data['termDays']);
		$this->assertSame('zienswijze', $data['legalRemedy']);
		$this->assertSame('alice', $data['openedBy']);
		$this->assertSame(
			'https://example.org/index.php/apps/opencatalogi/comment-periods/pub-1/zienswijze',
			$data['reactionFormUrl']
		);
		$this->assertSame('period-1', $data['id']);

	}//end testOpeningAPeriodAnswersWithTheStoredPeriod()

	/**
	 * Without a signed-in user the period records no opener rather than a
	 * guessed one.
	 *
	 * @return void
	 */
	public function testAPeriodOpenedWithoutASessionRecordsNoOpener(): void {
		$this->controller = $this->build(signedIn: false);
		$this->params($this->openParams(['automaticWithdrawal' => 'true']));

		$response = $this->controller->open();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('', $data['openedBy']);
		$this->assertTrue($data['automaticWithdrawal']);

	}//end testAPeriodOpenedWithoutASessionRecordsNoOpener()

	/**
	 * A remedy the app does not know is refused with 400, because a reader told
	 * the wrong remedy loses a right.
	 *
	 * @return void
	 */
	public function testAnUnknownRemedyIsRefusedWith400(): void {
		$this->params($this->openParams(['legalRemedy' => 'beroep']));

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('period-refused', $response->getData()['error']);

	}//end testAnUnknownRemedyIsRefusedWith400()

	/**
	 * A period with no publication is refused with 400.
	 *
	 * @return void
	 */
	public function testAPeriodWithoutAPublicationIsRefusedWith400(): void {
		$this->params($this->openParams(['publication' => '']));

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('period-refused', $response->getData()['error']);

	}//end testAPeriodWithoutAPublicationIsRefusedWith400()

	/**
	 * Without the term engine the period is refused with 503, naming the engine
	 * rather than quoting an end date nobody computed.
	 *
	 * @return void
	 */
	public function testAnUnavailableTermEngineAnswers503(): void {
		$engineless = $this->createMock(ContainerInterface::class);
		$engineless->method('get')->willThrowException(new \RuntimeException('not installed'));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('42');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $this->objects);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new CommentPeriodController(
			'opencatalogi',
			$this->request,
			$container,
			$l10n,
			$userSession,
			new CommentPeriodService(new TermRoll($engineless, $appManager), $config, $urls)
		);
		$this->params($this->openParams());

		$response = $controller->open();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('term-engine-unavailable', $response->getData()['error']);

	}//end testAnUnavailableTermEngineAnswers503()

	/**
	 * Without OpenRegister the period cannot be stored, and the answer says so
	 * rather than reporting a period that was never written.
	 *
	 * @return void
	 */
	public function testAnUnavailableRegisterAnswers503OnOpen(): void {
		$this->controller = $this->build(registerAvailable: false);
		$this->params($this->openParams());

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('register-unreadable', $response->getData()['error']);

	}//end testAnUnavailableRegisterAnswers503OnOpen()

	/**
	 * An unconfigured register names the missing key instead of reporting an
	 * empty result.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterAnswers503OnOpen(): void {
		$this->controller = $this->build(configured: '');
		$this->params($this->openParams());

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('register_not_configured', $response->getData()['error']);

	}//end testAnUnconfiguredRegisterAnswers503OnOpen()

	/**
	 * A reader reads the period, its state and the form that belongs to it.
	 *
	 * @return void
	 */
	public function testAReaderSeesTheStateTheRemedyAndTheForm(): void {
		$this->objects->objects['period-1'] = [
			'publication' => 'pub-1',
			'startDate' => '2026-03-02T09:00:00+00:00',
			'endDate' => '2099-04-13T09:00:00+00:00',
			'termDays' => 42,
			'legalRemedy' => 'zienswijze',
			'reactionFormUrl' => 'https://example.org/form',
			'announcementUrl' => 'https://www.officielebekendmakingen.nl/stcrt-2026-1',
		];

		$response = $this->controller->show(id: 'period-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('open', $data['state']);
		$this->assertSame('zienswijze', $data['legalRemedy']);
		$this->assertSame('https://example.org/form', $data['reactionFormUrl']);

	}//end testAReaderSeesTheStateTheRemedyAndTheForm()

	/**
	 * A closed period is not offered a reaction form.
	 *
	 * @return void
	 */
	public function testAClosedPeriodOffersNoForm(): void {
		$this->objects->objects['period-1'] = [
			'publication' => 'pub-1',
			'startDate' => '2026-01-05T09:00:00+00:00',
			'endDate' => '2026-02-16T09:00:00+00:00',
			'termDays' => 42,
			'legalRemedy' => 'bezwaar',
			'reactionFormUrl' => 'https://example.org/form',
			'announcementUrl' => 'https://www.officielebekendmakingen.nl/stcrt-2026-2',
		];

		$data = $this->controller->show(id: 'period-1')->getData();

		$this->assertSame('closed', $data['state']);
		$this->assertNull($data['reactionFormUrl']);

	}//end testAClosedPeriodOffersNoForm()

	/**
	 * A period that is not there answers 404 rather than an empty period.
	 *
	 * @return void
	 */
	public function testAnUnknownPeriodAnswers404(): void {
		$response = $this->controller->show(id: 'period-nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('unknown-period', $response->getData()['error']);

	}//end testAnUnknownPeriodAnswers404()

	/**
	 * A register row that reads as nothing is a missing period, not an empty
	 * one.
	 *
	 * @return void
	 */
	public function testAPeriodThatReadsAsNothingAnswers404(): void {
		$this->objects->objects['period-1'] = [];

		$response = $this->controller->show(id: 'period-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('unknown-period', $response->getData()['error']);

	}//end testAPeriodThatReadsAsNothingAnswers404()

	/**
	 * Dates that cannot be read are refused rather than guessed, because open
	 * invites comment on a closed draft and closed withholds a right.
	 *
	 * @return void
	 */
	public function testAPeriodWithUnreadableDatesAnswers503(): void {
		$this->objects->objects['period-1'] = [
			'publication' => 'pub-1',
			'startDate' => 'not a date at all',
			'endDate' => 'nor is this',
			'legalRemedy' => 'zienswijze',
		];

		$response = $this->controller->show(id: 'period-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('unreadable-period', $response->getData()['error']);

	}//end testAPeriodWithUnreadableDatesAnswers503()

	/**
	 * Without OpenRegister a read answers 503 rather than 404, because the
	 * period may well exist.
	 *
	 * @return void
	 */
	public function testAnUnavailableRegisterAnswers503OnShow(): void {
		$this->controller = $this->build(registerAvailable: false);

		$response = $this->controller->show(id: 'period-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('register-unreadable', $response->getData()['error']);

	}//end testAnUnavailableRegisterAnswers503OnShow()

	/**
	 * An unconfigured register names the missing key on a read too.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterAnswers503OnShow(): void {
		$this->controller = $this->build(configured: '');

		$response = $this->controller->show(id: 'period-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('register_not_configured', $response->getData()['error']);

	}//end testAnUnconfiguredRegisterAnswers503OnShow()
}//end class
