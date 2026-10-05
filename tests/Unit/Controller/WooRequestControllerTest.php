<?php

/**
 * Unit tests for WooRequestController.
 *
 * These assert on the RESPONSE, because a refusal that reads as success in a
 * browser is the failure that matters here. A second extension refused inside the
 * service and then answered 200 would pass every service test.
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

use OCA\OpenCatalogi\Controller\WooRequestController;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException;
use OCA\OpenCatalogi\Service\Woo\TermRefusedException;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A duck-typed fake of the consumed OpenRegister ObjectService.
 */
class WooRequestFakeObjectService {

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
		$id = (string)($uuid ?? '');
		if ($id === '') {
			$id = (string)($object['id'] ?? '');
		}

		if ($id === '') {
			$this->counter++;
			$id = 'req-' . $this->counter;
		}

		$object['id'] = $id;
		$this->objects[$id] = $object;

		return $object;
	}//end saveObject()

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		return ['results' => array_values($this->objects)];
	}//end searchObjectsPaginated()
}//end class

/**
 * Unit tests for WooRequestController.
 */
class WooRequestControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private StatutoryTerm|MockObject $terms;
	private WooRequestFakeObjectService $objects;
	private WooRequestController $controller;

	/**
	 * Build the controller over a faked register and a mocked term adapter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->objects = new WooRequestFakeObjectService();

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('42');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($config) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		// onlyMethods against the real class: a double that could invent a
		// method the real adapter lacks would let a green test cover a call
		// that 500s in production.
		$this->terms = $this->getMockBuilder(StatutoryTerm::class)
			->disableOriginalConstructor()
			->onlyMethods(['arm', 'extendOnce', 'pause', 'resume', 'describe'])
			->getMock();

		$this->controller = new WooRequestController(
			'opencatalogi',
			$this->request,
			$container,
			$l10n,
			$userSession,
			new WooRequestService(),
			$this->terms
		);

	}//end setUp()

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
	 * Put a request in the register.
	 *
	 * @param array<string, mixed> $overrides Properties to set.
	 *
	 * @return string The request id.
	 */
	private function storedRequest(array $overrides = []): string {
		$record = array_merge(
			[
				'id' => 'req-1',
				'reference' => 'WOO-2026-ABCDEF',
				'receivedAt' => '2026-03-02T09:00:00+00:00',
				'requestedInformation' => 'Everything.',
				'status' => 'in_progress',
				'termTimer' => 'timer-1',
				'extensionCount' => 0,
			],
			$overrides
		);
		$this->objects->objects['req-1'] = $record;

		return 'req-1';

	}//end storedRequest()

	/**
	 * Intake stores the request, arms the term, and answers with the clock.
	 *
	 * @return void
	 */
	public function testIntakeAnswersWithTheReferenceAndTheDueDate(): void {
		$this->params(['requestedInformation' => 'Every email about the bridge.', 'channel' => 'web']);
		$this->terms->method('arm')->willReturn(
			[
				'timer' => 'timer-1',
				'dueAt' => '2026-03-30T09:00:00+00:00',
				'state' => 'armed',
				'extensionCount' => 0,
				'extensionMax' => 1,
				'extendable' => true,
			]
		);

		$response = $this->controller->receive();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertMatchesRegularExpression('/^WOO-2026-[0-9A-F]{6}$/', $data['receipt']['reference']);
		$this->assertSame('2026-03-30T09:00:00+00:00', $data['receipt']['dueAt']);
		$this->assertTrue($data['receipt']['termArmed']);
		$this->assertSame('timer-1', $data['request']['termTimer']);

	}//end testIntakeAnswersWithTheReferenceAndTheDueDate()

	/**
	 * A request that asks for nothing is refused with 400.
	 *
	 * @return void
	 */
	public function testIntakeRefusesARequestForNothing(): void {
		$this->params(['requestedInformation' => '']);

		$response = $this->controller->receive();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('request-refused', $response->getData()['error']);

	}//end testIntakeRefusesARequestForNothing()

	/**
	 * When the engine is unavailable the request is still stored, and the
	 * response says the term was NOT armed rather than quoting a date nobody
	 * computed.
	 *
	 * @return void
	 */
	public function testIntakeIsHonestWhenTheTermCouldNotBeArmed(): void {
		$this->params(['requestedInformation' => 'Everything.']);
		$this->terms->method('arm')->willThrowException(new TermEngineUnavailableException('no engine'));

		$response = $this->controller->receive();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame('term-not-armed', $data['error']);
		$this->assertFalse($data['receipt']['termArmed']);
		$this->assertNull($data['receipt']['dueAt']);
		$this->assertCount(1, $this->objects->objects, 'A request that arrived has arrived, and is stored.');

	}//end testIntakeIsHonestWhenTheTermCouldNotBeArmed()

	/**
	 * The first extension is granted and recorded on the request.
	 *
	 * @return void
	 */
	public function testTheFirstExtensionIsGranted(): void {
		$this->storedRequest();
		$this->params(['rationale' => 'The set is large.']);
		$this->terms->method('extendOnce')->willReturn(
			[
				'timer' => 'timer-1',
				'dueAt' => '2026-04-13T09:00:00+00:00',
				'state' => 'armed',
				'extensionCount' => 1,
				'extensionMax' => 1,
				'extendable' => false,
			]
		);

		$response = $this->controller->extend(requestId: 'req-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['request']['extensionCount']);
		$this->assertSame('The set is large.', $response->getData()['request']['extensionReason']);

	}//end testTheFirstExtensionIsGranted()

	/**
	 * 🔴 A refused SECOND extension answers 409, not 200.
	 *
	 * @return void
	 */
	public function testARefusedSecondExtensionAnswers409(): void {
		$this->storedRequest(['extensionCount' => 1]);
		$this->params(['rationale' => 'Still large.']);
		$this->terms->method('extendOnce')->willThrowException(
			new TermRefusedException("Timer 'timer-1' has reached its extension bound of 1.")
		);

		$response = $this->controller->extend(requestId: 'req-1');

		$this->assertSame(
			Http::STATUS_CONFLICT,
			$response->getStatus(),
			'A refusal must not answer 200. This controller extends Controller, not OCSController, for exactly this reason.'
		);
		$this->assertSame('extension-refused', $response->getData()['error']);
		$this->assertStringContainsString('extension bound of 1', $response->getData()['message']);
		$this->assertSame(1, $this->objects->objects['req-1']['extensionCount'], 'The stored request must be untouched.');

	}//end testARefusedSecondExtensionAnswers409()

	/**
	 * An extension with no rationale is refused with 400.
	 *
	 * @return void
	 */
	public function testAnExtensionWithoutARationaleIsRefused(): void {
		$this->storedRequest();
		$this->params(['rationale' => '   ']);

		$response = $this->controller->extend(requestId: 'req-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('rationale-required', $response->getData()['error']);

	}//end testAnExtensionWithoutARationaleIsRefused()

	/**
	 * A request with no term cannot be extended, and says so with 409.
	 *
	 * @return void
	 */
	public function testARequestWithNoTermCannotBeExtended(): void {
		$this->storedRequest(['termTimer' => '']);
		$this->params(['rationale' => 'Why not.']);

		$response = $this->controller->extend(requestId: 'req-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('no-term', $response->getData()['error']);

	}//end testARequestWithNoTermCannotBeExtended()

	/**
	 * A pause suspends the term and moves the request to awaiting clarification.
	 *
	 * @return void
	 */
	public function testAPauseSuspendsTheTermAndMovesTheRequest(): void {
		$this->storedRequest(['dueAt' => '2026-03-30T09:00:00+00:00']);
		$this->params(['reason' => 'The request is too general.']);
		$this->terms->method('pause')->willReturn(
			[
				'timer' => 'timer-1',
				'dueAt' => null,
				'state' => 'suspended',
				'extensionCount' => 0,
				'extensionMax' => 1,
				'extendable' => true,
			]
		);

		$response = $this->controller->pause(requestId: 'req-1');
		$stored = $this->objects->objects['req-1'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('awaiting_clarification', $stored['status']);
		$this->assertSame('suspended', $response->getData()['term']['state']);
		$this->assertArrayNotHasKey(
			'dueAt',
			$stored,
			'A suspended term has no due moment, and null must not be written into a date-time property.'
		);

	}//end testAPauseSuspendsTheTermAndMovesTheRequest()

	/**
	 * A pause with no reason is refused with 400.
	 *
	 * @return void
	 */
	public function testAPauseWithoutAReasonIsRefused(): void {
		$this->storedRequest();
		$this->params(['reason' => '']);

		$response = $this->controller->pause(requestId: 'req-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('reason-required', $response->getData()['error']);

	}//end testAPauseWithoutAReasonIsRefused()

	/**
	 * A resume puts the request back to work.
	 *
	 * @return void
	 */
	public function testAResumePutsTheRequestBackToWork(): void {
		$this->storedRequest(['status' => 'awaiting_clarification']);
		$this->params(['reason' => 'The requester answered.']);
		$this->terms->method('resume')->willReturn(
			[
				'timer' => 'timer-1',
				'dueAt' => '2026-04-02T09:00:00+00:00',
				'state' => 'armed',
				'extensionCount' => 0,
				'extensionMax' => 1,
				'extendable' => true,
			]
		);

		$response = $this->controller->resume(requestId: 'req-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('in_progress', $this->objects->objects['req-1']['status']);
		$this->assertSame('2026-04-02T09:00:00+00:00', $this->objects->objects['req-1']['dueAt']);

	}//end testAResumePutsTheRequestBackToWork()

	/**
	 * A resume the engine refuses answers 409.
	 *
	 * @return void
	 */
	public function testARefusedResumeAnswers409(): void {
		$this->storedRequest();
		$this->params(['reason' => 'Nothing to resume.']);
		$this->terms->method('resume')->willThrowException(new TermRefusedException('its state is not suspended'));

		$response = $this->controller->resume(requestId: 'req-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testARefusedResumeAnswers409()

	/**
	 * A batch is attached to the request, and nothing is written to the batch.
	 *
	 * @return void
	 */
	public function testABatchIsAttachedToTheRequest(): void {
		$this->storedRequest(['status' => 'received']);
		$this->params(['batch' => 'batch-7']);

		$response = $this->controller->attachBatch(requestId: 'req-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('batch-7', $this->objects->objects['req-1']['batch']);
		$this->assertSame('in_progress', $this->objects->objects['req-1']['status']);

	}//end testABatchIsAttachedToTheRequest()

	/**
	 * An unknown request answers 404.
	 *
	 * @return void
	 */
	public function testAnUnknownRequestAnswers404(): void {
		$this->params(['rationale' => 'Why not.']);

		$response = $this->controller->extend(requestId: 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnUnknownRequestAnswers404()

	/**
	 * The report counts the stored requests.
	 *
	 * @return void
	 */
	public function testTheTermsReportReadsTheStoredRequests(): void {
		$this->storedRequest(['dueAt' => '2026-03-30T09:00:00+00:00', 'decidedAt' => '2026-03-20T09:00:00+00:00', 'status' => 'decided']);
		$this->params([]);

		$response = $this->controller->termsReport();
		$data = $response->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame(1, $data['counts']['met']);
		$this->assertSame(1.0, $data['metShare']);

	}//end testTheTermsReportReadsTheStoredRequests()
}//end class
