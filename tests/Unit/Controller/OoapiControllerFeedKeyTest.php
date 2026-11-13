<?php

/**
 * Unit tests for the feed consumer key path of OoapiController's gate.
 *
 * A request carrying `Authorization: Bearer oc_...` is served as the key's
 * consumer account on that consumer's own catalogue, refused with 403 on any
 * other catalogue and with 401 when the key is wrong, revoked or expired.
 * A key consumer does not need to be on the instance-wide account list.
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

use OCA\OpenCatalogi\Contract\FeedConsumerServiceInterface;
use OCA\OpenCatalogi\Controller\OoapiController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\OoapiService;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the feed consumer key path of the OOAPI gate.
 */
#[CoversClass(OoapiController::class)]
#[UsesClass(OpenRegisterLookup::class)]
class OoapiControllerFeedKeyTest extends TestCase {

	private const KEY = 'oc_consumer-1.k1.secret';

	private IRequest|MockObject $request;
	private OoapiService|MockObject $ooapiService;
	private CatalogiService|MockObject $catalogiService;
	private IUserSession|MockObject $userSession;
	private FeedConsumerServiceInterface|MockObject $feedConsumers;
	private ContainerInterface|MockObject $container;
	private OoapiController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->ooapiService = $this->createMock(OoapiService::class);
		$this->catalogiService = $this->createMock(CatalogiService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->feedConsumers = $this->createMock(FeedConsumerServiceInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);
		$this->ooapiService->method('isOoapiEnabled')->willReturn(true);
		$this->container->method('get')->willReturnCallback(
			static function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
					return new class {
						public function lookupId(\OCA\OpenRegister\Service\ConfigLookup $lookup): string {
							return '3';
						}
					};
				}

				throw new \RuntimeException('unexpected container lookup: ' . $id);
			}
		);

		$this->controller = new OoapiController(
			'opencatalogi',
			$this->request,
			$this->ooapiService,
			$this->catalogiService,
			$this->userSession,
			$l10n,
			$this->createMock(LoggerInterface::class),
			new OpenRegisterLookup($this->container, $this->createMock(IAppManager::class)),
			null,
			$this->feedConsumers
		);
	}

	/**
	 * Send this Authorization header.
	 *
	 * @param string $value The header value.
	 *
	 * @return void
	 */
	private function withAuthorization(string $value): void {
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === 'Authorization' ? $value : ''
		);
	}

	/**
	 * The key resolves to a consumer of this catalogue, acting as this account.
	 *
	 * @param string $catalogId The consumer's catalogue.
	 *
	 * @return IUser|MockObject The account.
	 */
	private function givenValidKeyFor(string $catalogId): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('feed-partner-abc123');
		$this->feedConsumers->method('authenticate')->with(self::KEY)
			->willReturn(['consumer' => ['id' => 'consumer-1', 'catalog' => $catalogId], 'user' => $user]);
		return $user;
	}

	public function testAValidKeyReadsItsOwnCatalogueAsTheConsumerAccount(): void {
		$this->withAuthorization('Bearer ' . self::KEY);
		$user = $this->givenValidKeyFor('cat-1');
		$this->catalogiService->method('getCatalogBySlug')->with('hva-onderwijs')->willReturn(['id' => 'cat-1']);
		$this->userSession->expects($this->once())->method('setVolatileActiveUser')->with($user);
		$this->ooapiService->expects($this->never())->method('isConsumerAllowed');
		$this->ooapiService->expects($this->once())->method('listCourses')
			->willReturn(['items' => [['courseId' => 'c1']], 'pageNumber' => 1, 'pageSize' => 25, 'hasNext' => false]);

		$response = $this->controller->courses('hva-onderwijs');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['courseId' => 'c1']], $response->getData()['items']);
	}

	public function testTheSameKeyOnAnotherCatalogueIsRefusedBeforeAnyRead(): void {
		$this->withAuthorization('Bearer ' . self::KEY);
		$this->givenValidKeyFor('cat-1');
		$this->catalogiService->method('getCatalogBySlug')->with('uva-onderwijs')->willReturn(['@self' => ['id' => 'cat-2']]);
		$this->ooapiService->expects($this->never())->method('listCourses');
		$this->ooapiService->expects($this->never())->method('getResource');

		$this->assertSame(403, $this->controller->courses('uva-onderwijs')->getStatus());
		$this->assertSame(403, $this->controller->course('uva-onderwijs', 'c1')->getStatus());
	}

	public function testACatalogueWithoutIdIsRefusedForAKeyConsumer(): void {
		$this->withAuthorization('Bearer ' . self::KEY);
		$this->givenValidKeyFor('');
		$this->catalogiService->method('getCatalogBySlug')->willReturn(['slug' => 'x']);

		$this->assertSame(403, $this->controller->programs('x')->getStatus());
	}

	public function testARefusedKeyAnswers401AndActsAsNoOne(): void {
		$this->withAuthorization('Bearer ' . self::KEY);
		$this->feedConsumers->method('authenticate')->with(self::KEY)->willReturn(null);
		$this->userSession->expects($this->never())->method('setVolatileActiveUser');
		$this->userSession->expects($this->never())->method('getUser');
		$this->catalogiService->expects($this->never())->method('getCatalogBySlug');

		$response = $this->controller->courses('hva-onderwijs');

		$this->assertSame(401, $response->getStatus());
	}

	public function testAnotherBearerTokenIsLeftToTheNextcloudSession(): void {
		$this->withAuthorization('Bearer nextcloud-app-token');
		$this->feedConsumers->expects($this->never())->method('authenticate');
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(401, $this->controller->courses('hva-onderwijs')->getStatus());
	}

	public function testASessionAccountIsNotLimitedToOneCatalogue(): void {
		$this->withAuthorization('');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('surf-consumer');
		$this->userSession->method('getUser')->willReturn($user);
		$this->ooapiService->method('isConsumerAllowed')->with('surf-consumer')->willReturn(true);
		$this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-2']);
		$this->ooapiService->method('listPrograms')
			->willReturn(['items' => [], 'pageNumber' => 1, 'pageSize' => 25, 'hasNext' => false]);

		$this->assertSame(200, $this->controller->programs('uva-onderwijs')->getStatus());
	}
}
