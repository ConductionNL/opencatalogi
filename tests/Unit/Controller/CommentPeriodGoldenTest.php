<?php

/**
 * Golden test for CommentPeriodController::open() and ::show().
 *
 * Every case opens or reads one period over recording doubles and compares the
 * answer (status and body) and every call, in order, to the recording in
 * fixtures/comment-period-golden.json.
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

use OCA\OpenCatalogi\Contract\CommentPeriodRequest;
use OCA\OpenCatalogi\Contract\CommentPeriodServiceInterface;
use OCA\OpenCatalogi\Controller\CommentPeriodController;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A recording double of the consumed OpenRegister ObjectService.
 */
class CommentPeriodGoldenObjects {

	/**
	 * Constructor.
	 *
	 * @param \Closure             $record Records one call.
	 * @param array<string, mixed> $case   The case.
	 */
	public function __construct(
		private readonly \Closure $record,
		private readonly array $case,
	) {
	}//end __construct()

	/**
	 * Find a period.
	 *
	 * @param string $id       The id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return mixed The stored period.
	 */
	public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
		($this->record)(['find' => [$id, $register, $schema]]);
		if (($this->case['fail'] ?? null) === 'find') {
			throw new RuntimeException('not found');
		}

		return $this->wrap(data: ($this->case['stored'] ?? []));
	}//end find()

	/**
	 * Save a period.
	 *
	 * @param array<string, mixed> $object        The period.
	 * @param mixed                $register      The register.
	 * @param mixed                $schema        The schema.
	 * @param bool                 $_rbac         Whether RBAC applies.
	 * @param bool                 $_multitenancy Whether multitenancy applies.
	 *
	 * @return mixed The saved period.
	 */
	public function saveObject(
		array $object,
		mixed $register = null,
		mixed $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true
	): mixed {
		($this->record)(['saveObject' => [$object, $register, $schema, $_rbac, $_multitenancy]]);
		if (($this->case['fail'] ?? null) === 'save') {
			throw new RuntimeException('register write failed');
		}

		return $this->wrap(data: $object + ['id' => 'period-1']);
	}//end saveObject()

	/**
	 * Answer as an array or as an entity, as the case asks.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return mixed The answer.
	 */
	private function wrap(array $data): mixed {
		if (($this->case['entity'] ?? false) === true) {
			return new class (['id' => ($data['id'] ?? null), 'object' => $data]) implements \JsonSerializable {
				/**
				 * Constructor.
				 *
				 * @param array<string, mixed> $data The serialised entity.
				 */
				public function __construct(private readonly array $data) {
				}//end __construct()

				/**
				 * The serialised entity.
				 *
				 * @return array<string, mixed> The data.
				 */
				public function jsonSerialize(): array {
					return $this->data;
				}//end jsonSerialize()
			};
		}

		return $data;
	}//end wrap()
}//end class

/**
 * Golden test for opening and reading a comment period.
 *
 * @covers \OCA\OpenCatalogi\Controller\CommentPeriodController
 * @covers \OCA\OpenCatalogi\Controller\ResolvesRegisterConfiguration
 * @covers \OCA\OpenCatalogi\Controller\ReadsOpenRegisterResults
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 * @uses   \OCA\OpenCatalogi\Contract\CommentPeriodRequest
 */
class CommentPeriodGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/comment-period-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded cases: the action, request parameters, the session and what fails.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$form = [
			'publication' => ' pub-1 ',
			'termDays' => '42',
			'legalRemedy' => ' zienswijze ',
			'announcementUrl' => ' https://www.officielebekendmakingen.nl/stcrt-2026-1 ',
			'automaticWithdrawal' => 'true',
		];
		$forged = $form + ['openedBy' => 'mallory', 'userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory'];
		$stored = ['publication' => 'pub-1', 'endDate' => '2099-04-13T09:00:00+00:00', 'legalRemedy' => 'zienswijze'];

		return [
			'open: stored' => [['action' => 'open', 'params' => $form]],
			'open: a forged openedBy, userId, requestedBy and owner are ignored' => [['action' => 'open', 'params' => $forged]],
			'open: no parameters' => [['action' => 'open', 'params' => []]],
			'open: not signed in' => [['action' => 'open', 'user' => false, 'params' => $form]],
			'open: automatic withdrawal off' => [['action' => 'open', 'params' => ['automaticWithdrawal' => 'no'] + $form]],
			'open: refused' => [['action' => 'open', 'fail' => 'refuse', 'params' => $form]],
			'open: term engine unavailable' => [['action' => 'open', 'fail' => 'engine', 'params' => $form]],
			'open: service throws something else' => [['action' => 'open', 'fail' => 'openOther', 'params' => $form]],
			'open: an invalid argument is not a refusal' => [['action' => 'open', 'fail' => 'invalid', 'params' => $form]],
			'open: register unavailable' => [['action' => 'open', 'fail' => 'objects', 'params' => $form]],
			'open: register not configured' => [['action' => 'open', 'configured' => false, 'params' => $form]],
			'open: app config unavailable' => [['action' => 'open', 'fail' => 'appConfig', 'params' => $form]],
			'open: save fails' => [['action' => 'open', 'fail' => 'save', 'params' => $form]],
			'open: saved as an entity' => [['action' => 'open', 'entity' => true, 'params' => $form]],
			'show: found' => [['action' => 'show', 'stored' => $stored]],
			'show: a forged register, schema and id are ignored' => [['action' => 'show', 'stored' => $stored, 'params' => ['register' => '99', 'schema' => '99', 'id' => 'other']]],
			'show: found as an entity' => [['action' => 'show', 'entity' => true, 'stored' => $stored]],
			'show: register unavailable' => [['action' => 'show', 'fail' => 'objects']],
			'show: register not configured' => [['action' => 'show', 'configured' => false]],
			'show: find throws' => [['action' => 'show', 'fail' => 'find']],
			'show: reads as nothing' => [['action' => 'show', 'stored' => []]],
			'show: unreadable dates' => [['action' => 'show', 'fail' => 'view', 'stored' => $stored]],
			'show: view throws something else' => [['action' => 'show', 'fail' => 'viewOther', 'stored' => $stored]],
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
	public function testCommentPeriodMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		try {
			$controller = $this->controller(case: $case);
			if ($case['action'] === 'open') {
				$response = $controller->open();
			} else {
				$response = $controller->show(id: 'period-1');
			}

			$data = $response->getData();
			if (isset($data['detail']) === true) {
				$data['detail'] = preg_replace('/ passed in .* on line \\d+/', ' passed', (string)$data['detail']);
			}

			$actual = ['status' => $response->getStatus(), 'data' => $data, 'calls' => $this->calls];
		} catch (\Throwable $e) {
			$actual = ['thrown' => get_class($e) . ': ' . $e->getMessage(), 'calls' => $this->calls];
		}

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

	}//end testCommentPeriodMatchesTheRecording()

	/**
	 * Opening a period is an admin setting: exactly one AuthorizedAdminSetting
	 * naming the OpenCatalogi admin section, and nothing that opens it wider.
	 *
	 * @return void
	 */
	public function testOpenIsGuardedByTheAdminSettingOnly(): void {
		$method = new \ReflectionMethod(CommentPeriodController::class, 'open');

		$admin = $method->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $admin);
		$this->assertSame(OpenCatalogiAdmin::class, $admin[0]->newInstance()->getSettings());
		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
		$this->assertSame([], $method->getAttributes(PublicPage::class));

	}//end testOpenIsGuardedByTheAdminSettingOnly()

	/**
	 * The controller over recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return CommentPeriodController The controller.
	 */
	private function controller(array $case): CommentPeriodController {
		$params = ($case['params'] ?? []);
		$fail = ($case['fail'] ?? null);
		$record = function (array $call): void {
			$this->calls[] = $call;
		};

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ambtenaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn((($case['user'] ?? true) === true) ? $user : null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case, $record): string {
				$record(['getValueString' => [$app, $key, $default]]);
				if (($case['configured'] ?? true) === false) {
					return '';
				}

				return ['publication_register' => '7', 'comment_period_schema' => '9'][$key] ?? 'unexpected';
			}
		);

		$objects = new CommentPeriodGoldenObjects(record: $record, case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($fail, $objects, $appConfig, $record): mixed {
				$record(['container' => $id]);
				if ($id === 'OCA\OpenRegister\Service\ObjectService' && $fail !== 'objects') {
					return $objects;
				}

				if ($id === IAppConfig::class && $fail !== 'appConfig') {
					return $appConfig;
				}

				throw new RuntimeException('not bound: ' . $id);
			}
		);

		$periods = $this->createMock(CommentPeriodServiceInterface::class);
		$periods->method('open')->willReturnCallback(
			function (CommentPeriodRequest $request) use ($fail, $record): array {
				$fields = [
					$request->publication,
					$request->termDays,
					$request->legalRemedy,
					$request->announcementUrl,
					$request->automaticWithdrawal,
					$request->openedBy,
				];
				$record(['open' => $fields]);
				if ($fail === 'refuse') {
					throw new \DomainException('unknown remedy');
				}

				if ($fail === 'engine') {
					throw new TermRollUnavailableException('engine down');
				}

				if ($fail === 'invalid') {
					throw new \InvalidArgumentException('bad argument');
				}

				if ($fail === 'openOther') {
					throw new RuntimeException('unexpected');
				}

				return ['publication' => $request->publicationId(), 'openedBy' => $request->openedBy, 'termDays' => $request->termDays];
			}
		);
		$periods->method('publicView')->willReturnCallback(
			function (array $period) use ($fail, $record): array {
				$record(['publicView' => $period]);
				if ($fail === 'view') {
					throw new \DomainException('dates unreadable');
				}

				if ($fail === 'viewOther') {
					throw new RuntimeException('unexpected');
				}

				return ['state' => 'open'] + $period;
			}
		);

		return new CommentPeriodController('opencatalogi', $request, $container, $l10n, $session, $periods);
	}//end controller()
}//end class
