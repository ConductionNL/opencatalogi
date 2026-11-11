<?php

/**
 * Unit tests for PublicationStateController.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PublicationStateController;
use OCA\OpenCatalogi\Controller\PublicationTransitions;
use OCA\OpenCatalogi\Controller\RegisterAccess;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for publish, withdraw, publish again and withdraw one document.
 */
class PublicationStateControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	private PublicationRights&MockObject $rights;

	private NationalIndexService&MockObject $index;

	private object $fileService;

	/** @var array<string, mixed> The publication find() answers. */
	private array $publication = [];

	/** @var array<int, array<string, mixed>> Every saveObject() call, in order. */
	private array $saves = [];

	/** @var string|null The schema whose save throws, null when every save succeeds. */
	private ?string $failingSchema = null;

	private const GOLDEN = __DIR__ . '/fixtures/publication-withdraw-depublication-golden.json';

	/** @var array<int, string> Every channel a withdrawal was sent to. */
	private array $sent = [];

	private ?IUser $user = null;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				return in_array($key, ['publication_register', 'publication_schema', 'depublication_schema'], true) ? $key : $default;
			}
		);

		$this->fileService = new class {
			/** @var array<int, int|string> */
			public array $unpublished = [];

			public function unpublishFile(object $object, string|int $filePath): object {
				$this->unpublished[] = $filePath;
				return new \stdClass();
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($config) {
				if ($id === IAppConfig::class) {
					return $config;
				}

				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $this->fileService;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		$this->objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject'])
			->getMock();
		$this->objectService->method('find')->willReturnCallback(
			function () {
				if ($this->publication === []) {
					return null;
				}

				return $this->entity($this->publication);
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true) {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];
				if ($schema === $this->failingSchema) {
					throw new \RuntimeException('The save was refused.');
				}

				return $this->entity($object);
			}
		);

		$objects = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();
		$objects->method('getObjectService')->willReturn($this->objectService);

		$this->rights = $this->getMockBuilder(PublicationRights::class)
			->disableOriginalConstructor()
			->onlyMethods(['mayUpdate'])
			->getMock();

		$this->index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['withdraw'])
			->getMock();
		$this->index->method('withdraw')->willReturnCallback(
			function (string $channel) {
				$this->sent[] = $channel;
				if ($channel === 'plooi') {
					throw new IndexUnreachableException('No integriq source is set for the channel.');
				}

				return ['channel' => $channel, 'acknowledgedAt' => '2026-09-30T12:00:01+00:00', 'answer' => 'ok'];
			}
		);

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('redacteur');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PublicationStateController(
			'opencatalogi',
			$this->request,
			$l10n,
			$session,
			$this->rights,
			new PublicationTransitions(new PublicationStateService(), new DepublicationService($this->index, $this->createMock(LoggerInterface::class)), $container, new RegisterAccess(new OpenRegisterLookup($container, $this->createMock(IAppManager::class)), $objects))
		);
	}

	private PublicationStateController $controller;

	/**
	 * An ObjectEntity double whose jsonSerialize answers the given properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 */
	private function entity(array $properties): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($properties);
		return $entity;
	}

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
	}

	/** REQ-PPW-001: the page asks, the server answers public. */
	public function testVisibilityAnswersPublic(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$response = $this->controller->visibility('p1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['state' => 'public'], $response->getData());
	}

	/** REQ-PPW-001: a publication the caller cannot read answers 404. */
	public function testVisibilityOfAnUnreadablePublicationIs404(): void {
		$this->publication = [];
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->visibility('p1')->getStatus());
	}

	/**
	 * REQ-PPW-002: an editor withdraws a publication published by mistake.
	 *
	 * The publication is saved withdrawn FIRST, with RBAC on; then a
	 * depublication naming the editor, the reason and a withdrawal per channel
	 * is stored; the answer names the channel that did not confirm.
	 */
	public function testAnEditorWithdrawsAPublicationPublishedByMistake(): void {
		$this->publication = ['id' => 'p1', 'title' => 'Besluit', 'publicationDate' => '2026-09-01T09:00:00+00:00', 'plooiStatus' => 'delivered'];
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->withParams(['reason' => 'Wrong annex attached']);

		$response = $this->controller->withdraw('p1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($data));
		$this->assertSame('withdrawn', $data['state']);
		$this->assertSame(['plooi'], $data['outstandingChannels']);
		$this->assertCount(2, $this->saves);
		$this->assertSame('p1', $this->saves[0]['uuid']);
		$this->assertTrue($this->saves[0]['rbac']);
		$this->assertArrayHasKey('depublicationDate', $this->saves[0]['object']);
		$this->assertSame('withdrawn', (new PublicationStateService())->stateOf($this->saves[0]['object']));
		$depublication = $this->saves[1]['object'];
		$this->assertSame('depublication_schema', $this->saves[1]['schema']);
		$this->assertSame('redacteur', $depublication['depublishedBy']);
		$this->assertSame('Wrong annex attached', $depublication['reason']);
		$this->assertSame(['national-woo-index', 'plooi'], array_column($depublication['withdrawals'], 'channel'));
		$this->assertArrayNotHasKey('acknowledgedAt', $depublication['withdrawals'][1]);
	}

	/** REQ-PPW-002: a reader without update rights is refused and nothing is sent or saved. */
	public function testAReaderWithoutUpdateRightsIsRefused(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(false);
		$this->withParams(['reason' => 'Wrong annex attached']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->withdraw('p1')->getStatus());
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->sent);
	}

	/**
	 * The withdrawals the golden replays: action, publication, update right, parameters, session, failing schema.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool, 3: array<string, mixed>, 4: bool, 5: string|null}>
	 */
	public static function withdrawWorlds(): array {
		$public = ['id' => 'p1', 'title' => 'Besluit', 'publicationDate' => '2026-09-01T09:00:00+00:00', 'plooiStatus' => 'delivered'];
		$scheduled = ['id' => 'p1', 'publicationDate' => '2099-09-01T09:00:00+00:00'];
		$withdrawn = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00', 'depublicationDate' => '2026-09-29T09:00:00+00:00'];
		$reason = ['reason' => 'Wrong annex attached'];
		$forged = ['reason' => 'Wrong annex attached', 'depublishedBy' => 'someone-else', 'userId' => 'someone-else', 'actor' => 'someone-else'];

		return [
			'withdraw public' => ['withdraw', $public, true, $reason, true, null],
			'withdraw scheduled' => ['withdraw', $scheduled, true, $reason, true, null],
			'withdraw draft' => ['withdraw', ['id' => 'p1'], true, $reason, true, null],
			'withdraw withdrawn' => ['withdraw', $withdrawn, true, $reason, true, null],
			'withdraw without the update right' => ['withdraw', $public, false, $reason, true, null],
			'withdraw unreadable' => ['withdraw', [], true, $reason, true, null],
			'withdraw anonymous' => ['withdraw', $public, true, $reason, false, null],
			'withdraw without a reason' => ['withdraw', $public, true, [], true, null],
			'withdraw with a forged actor' => ['withdraw', $public, true, $forged, true, null],
			'withdraw, publication save refused' => ['withdraw', $public, true, $reason, true, 'publication_schema'],
			'withdraw, depublication save refused' => ['withdraw', $public, true, $reason, true, 'depublication_schema'],
			'withdraw file' => ['withdrawFile', $public, true, $reason, true, null],
			'withdraw file without the update right' => ['withdrawFile', $public, false, $reason, true, null],
			'withdraw file unreadable' => ['withdrawFile', [], true, $reason, true, null],
			'withdraw file anonymous' => ['withdrawFile', $public, true, $reason, false, null],
			'withdraw file without a reason' => ['withdrawFile', $public, true, [], true, null],
			'withdraw file with a forged actor' => ['withdrawFile', $public, true, $forged, true, null],
			'withdraw file, depublication save refused' => ['withdrawFile', $public, true, $reason, true, 'depublication_schema'],
		];

	}//end withdrawWorlds()

	/**
	 * What a withdrawal answers, saves and sends matches the recording.
	 *
	 * @param string $action withdraw or withdrawFile.
	 * @param array<string, mixed> $publication The publication find() answers.
	 * @param bool $mayUpdate Whether the caller may update it.
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool $signedIn Whether there is a session.
	 * @param string|null $failingSchema The schema whose save throws.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('withdrawWorlds')]
	public function testWithdrawMatchesTheRecording(string $action, array $publication, bool $mayUpdate, array $params, bool $signedIn, ?string $failingSchema): void {
		$this->publication = $publication;
		$this->rights->method('mayUpdate')->willReturn($mayUpdate);
		$this->withParams($params);
		$this->failingSchema = $failingSchema;
		if ($signedIn === false) {
			$this->user = null;
		}

		$response = $action === 'withdraw' ? $this->controller->withdraw('p1') : $this->controller->withdrawFile('p1', '4711');
		$actual = $this->withoutMoments(
			value: [
				'status' => $response->getStatus(),
				'data' => $response->getData(),
				'saves' => array_map(static fn (array $save): array => array_diff_key($save, ['rbac' => true]), $this->saves),
				'sent' => $this->sent,
				'unpublished' => $this->fileService->unpublished,
			]
		);

		$recorded = [];
		if (file_exists(self::GOLDEN) === true) {
			$recorded = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::GOLDEN, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testWithdrawMatchesTheRecording()

	/**
	 * Replace every moment within ten minutes of now with a marker, so a recording does not age.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return mixed The value with its current moments replaced.
	 */
	private function withoutMoments(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->withoutMoments(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1
			&& abs(strtotime($value) - time()) < 600
		) {
			return '<now>';
		}

		return $value;
	}//end withoutMoments()

	/**
	 * The depublication is stored without RBAC once the guard passed, the publication with RBAC.
	 *
	 * The depublication schema is writable by administrators only, so an editor's
	 * withdrawal stores its record through the app after the update check.
	 */
	public function testTheDepublicationIsStoredWithoutRbacAfterTheGuard(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->withParams(['reason' => 'Wrong annex attached', 'depublishedBy' => 'someone-else']);

		$this->assertSame(Http::STATUS_OK, $this->controller->withdraw('p1')->getStatus());
		$this->assertSame(['publication_schema', 'depublication_schema'], array_column($this->saves, 'schema'));
		$this->assertTrue($this->saves[0]['rbac']);
		$this->assertFalse($this->saves[1]['rbac']);
		$this->assertSame('redacteur', $this->saves[1]['object']['depublishedBy']);

		$this->saves = [];
		$this->assertSame(Http::STATUS_OK, $this->controller->withdrawFile('p1', '4711')->getStatus());
		$this->assertSame(['depublication_schema'], array_column($this->saves, 'schema'));
		$this->assertFalse($this->saves[0]['rbac']);
		$this->assertSame('redacteur', $this->saves[0]['object']['depublishedBy']);
	}

	/** A caller the guard refuses stores no depublication, for either withdrawal. */
	public function testARefusedCallerStoresNoDepublication(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(false);
		$this->withParams(['reason' => 'Wrong annex attached', 'depublishedBy' => 'redacteur']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->withdraw('p1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->withdrawFile('p1', '4711')->getStatus());
		$this->assertSame([], $this->saves);

		$this->publication = [];
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->withdraw('p1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->withdrawFile('p1', '4711')->getStatus());
		$this->assertSame([], $this->saves);
	}

	/** REQ-PPW-002: a withdrawal without a reason is refused. */
	public function testAWithdrawalWithoutAReasonIsRefused(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->withParams(['reason' => '  ']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->withdraw('p1')->getStatus());
		$this->assertSame([], $this->saves);
	}

	/** Design risk: a second withdraw finds it withdrawn and answers 409 without a second depublication. */
	public function testASecondWithdrawAnswers409(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00', 'depublicationDate' => '2026-09-29T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->withParams(['reason' => 'Wrong annex attached']);

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->withdraw('p1')->getStatus());
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->sent);
	}

	/** REQ-PPW-003: publish again makes a withdrawn publication public and stores no new depublication. */
	public function testAnEditorPublishesAgain(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00', 'depublicationDate' => '2026-09-29T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(true);

		$response = $this->controller->publish('p1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['state' => 'public'], $response->getData());
		$this->assertCount(1, $this->saves);
		$this->assertArrayNotHasKey('depublicationDate', $this->saves[0]['object']);
		$this->assertTrue($this->saves[0]['rbac']);
	}

	/** REQ-PPW-002: publish now on a public publication answers 409; without rights 403. */
	public function testPublishRefusals(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturnOnConsecutiveCalls(true, false);

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->publish('p1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->publish('p1')->getStatus());
		$this->assertSame([], $this->saves);
	}

	/** REQ-PPW-004: one annex comes down, the publication stays, and the depublication names the annex. */
	public function testAnAnnexWithPersonalDataIsWithdrawnAlone(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->withParams(['reason' => 'Contains a home address']);

		$response = $this->controller->withdrawFile('p1', '4711');

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame([4711], $this->fileService->unpublished);
		$this->assertCount(1, $this->saves, 'Only the depublication is saved; the publication stays as it is.');
		$this->assertSame('4711', $this->saves[0]['object']['file']);
		$this->assertSame('Contains a home address', $this->saves[0]['object']['reason']);
		$this->assertSame('redacteur', $this->saves[0]['object']['depublishedBy']);
	}

	/** REQ-PPW-004: without the update right the file keeps its share. */
	public function testAFileWithdrawalWithoutRightsIsRefused(): void {
		$this->publication = ['id' => 'p1', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$this->rights->method('mayUpdate')->willReturn(false);
		$this->withParams(['reason' => 'Contains a home address']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->withdrawFile('p1', '4711')->getStatus());
		$this->assertSame([], $this->fileService->unpublished);
	}

	/** Every write refuses a caller without a session. */
	public function testAnonymousCallersAreRefused(): void {
		$this->user = null;
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->publish('p1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->withdraw('p1')->getStatus());
	}
}
