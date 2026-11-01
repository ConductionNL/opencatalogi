<?php

/**
 * Golden test for PublicationStateController::withdraw().
 *
 * Every case withdraws a publication over recording doubles and compares the
 * answer and the ordered calls to the recording in
 * fixtures/publication-withdraw-golden.json.
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

use OCA\OpenCatalogi\Contract\DepublicationServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationStateController;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for PublicationStateController::withdraw().
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationStateController
 */
class PublicationWithdrawGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-withdraw-golden.json';

	private const FORGED = ['userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory', 'depublishedBy' => 'mallory', 'user' => 'mallory'];

	/**
	 * The recorded cases.
	 *
	 * Keys: session (uid or null), find (properties, null or an exception name),
	 * appConfig (false when IAppConfig is unavailable), may, state, params,
	 * saveFails (publication or depublication => exception name), outstanding.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$why = ['reason' => 'Published in error'];

		return [
			'no session' => [['session' => null, 'params' => $why]],
			'not found' => [['find' => null, 'params' => $why]],
			'find does not exist' => [['find' => 'missing', 'params' => $why]],
			'find not authorised' => [['find' => 'denied', 'params' => $why]],
			'find fails otherwise' => [['find' => 'boom', 'params' => $why]],
			'app config unavailable' => [['appConfig' => false, 'params' => $why]],
			'may not update' => [['may' => false, 'params' => $why]],
			'another session user' => [['session' => 'other', 'params' => $why]],
			'no reason' => [['params' => []]],
			'blank reason' => [['params' => ['reason' => "  \t "]]],
			'reason null' => [['params' => ['reason' => null]]],
			'reason an int' => [['params' => ['reason' => 5]]],
			'reason padded' => [['params' => ['reason' => '  Wrong file  ']]],
			'draft' => [['state' => 'draft', 'params' => $why]],
			'withdrawn' => [['state' => 'withdrawn', 'params' => $why]],
			'archived' => [['state' => 'archived', 'params' => $why]],
			'unknown state' => [['state' => '', 'params' => $why]],
			'public' => [['state' => 'public', 'params' => $why]],
			'scheduled' => [['state' => 'scheduled', 'params' => $why]],
			'public, channels outstanding' => [['state' => 'public', 'params' => $why, 'outstanding' => ['plooi']]],
			'scheduled, channels outstanding' => [['state' => 'scheduled', 'params' => $why, 'outstanding' => ['koop']]],
			'save not authorised' => [['params' => $why, 'saveFails' => ['opencatalogi/publication_schema' => 'denied']]],
			'save fails' => [['params' => $why, 'saveFails' => ['opencatalogi/publication_schema' => 'boom']]],
			'depublication not stored' => [['params' => $why, 'saveFails' => ['opencatalogi/depublication_schema' => 'boom']]],
			'depublication store denied' => [['params' => $why, 'saveFails' => ['opencatalogi/depublication_schema' => 'denied']]],
			'forged actor' => [['params' => $why + self::FORGED]],
			'forged actor, scheduled' => [['state' => 'scheduled', 'params' => $why + self::FORGED]],
			'publication owned by someone else' => [['find' => ['id' => 'pub-1', 'title' => 'Plan', 'owner' => 'mallory'], 'params' => $why]],
			'forged withdrawnReason' => [['params' => $why + ['withdrawnReason' => 'forged', 'depublicationReason' => 'forged']]],
			'file, blank reason' => [['file' => true, 'params' => ['reason' => '   ']]],
			'file, padded reason' => [['file' => true, 'params' => ['reason' => '  x  '] + self::FORGED]],
			'file, forged withdrawnReason' => [['file' => true, 'params' => ['reason' => 'x', 'withdrawnReason' => 'forged']]],
			'file, unpublish fails' => [['file' => true, 'unpublishFails' => true, 'params' => ['reason' => 'x']]],
			'wrapped object' => [['find' => ['id' => 'uuid-9', 'object' => ['title' => 'Wrapped', 'id' => 'inner']], 'params' => $why]],
			'properties without id' => [['find' => ['title' => 'No id'], 'params' => $why]],
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
	public function testWithdrawMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$calls = [];

		$controller = $this->controller(case: $case, calls: $calls);
		$response = (($case['file'] ?? false) === true ? $controller->withdrawFile('pub-1', '17') : $controller->withdraw('pub-1'));

		$actual = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $calls];

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

	}//end testWithdrawMatchesTheRecording()

	/**
	 * The controller over recording doubles.
	 *
	 * @param array<string, mixed>          $case  The case.
	 * @param array<int, array<int, mixed>> $calls The ordered calls, filled by the doubles.
	 *
	 * @return PublicationStateController The controller.
	 */
	private function controller(array $case, array &$calls): PublicationStateController {
		$params = ($case['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default));
		$request->method('getParams')->willReturn($params);

		$uid = (array_key_exists('session', $case) === true ? $case['session'] : 'editor');
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$rights = $this->getMockBuilder(PublicationRights::class)->disableOriginalConstructor()->onlyMethods(['mayUpdate'])->getMock();
		$rights->method('mayUpdate')->willReturnCallback(
			static function (object $publication) use (&$calls, $session, $case): bool {
				$calls[] = ['mayUpdate', $publication->jsonSerialize()];
				return $session->getUser()?->getUID() === 'editor' && ($case['may'] ?? true) === true;
			}
		);

		$controller = new PublicationStateController(
			'opencatalogi',
			$request,
			$this->l10n(),
			$session,
			$this->states(case: $case, calls: $calls),
			$rights,
			$this->depublisher(case: $case, calls: $calls),
			$this->container(case: $case, calls: $calls),
			$this->objects(case: $case, calls: $calls)
		);

		return $controller;

	}//end controller()

	/**
	 * A localisation double that marks translated text.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		return $l10n;

	}//end l10n()

	/**
	 * A state service double answering the case's state.
	 *
	 * @param array<string, mixed>          $case  The case.
	 * @param array<int, array<int, mixed>> $calls The ordered calls.
	 *
	 * @return PublicationStateServiceInterface The double.
	 */
	private function states(array $case, array &$calls): PublicationStateServiceInterface {
		$states = $this->createMock(PublicationStateServiceInterface::class);
		$states->method('stateOf')->willReturnCallback(
			static function (array $publication, $now = null) use (&$calls, $case): string {
				$calls[] = ['stateOf', $publication, $now];
				return ($case['state'] ?? 'public');
			}
		);
		$states->method('withdrawnRecord')->willReturnCallback(
			static function (array $publication, $now = null) use (&$calls): array {
				$calls[] = ['withdrawnRecord', $publication, $now];
				return ['withdrawn' => true, 'id' => 'drop-me'] + $publication;
			}
		);
		$states->method('channelsReached')->willReturnCallback(
			static function (array $publication) use (&$calls): array {
				$calls[] = ['channelsReached', $publication];
				return ['plooi', 'koop'];
			}
		);
		$states->method('storable')->willReturnCallback(
			static function (array $depublication) use (&$calls): array {
				$calls[] = ['storable', $depublication];
				return ['stored' => $depublication];
			}
		);

		return $states;

	}//end states()

	/**
	 * A depublication service double that echoes what it was given.
	 *
	 * @param array<string, mixed>          $case  The case.
	 * @param array<int, array<int, mixed>> $calls The ordered calls.
	 *
	 * @return DepublicationServiceInterface The double.
	 */
	private function depublisher(array $case, array &$calls): DepublicationServiceInterface {
		$depublisher = $this->createMock(DepublicationServiceInterface::class);
		$depublisher->method('depublish')->willReturnCallback(
			static function (array $publication, string $reason, string $depublishedBy, array $channels, $now = null) use (&$calls): array {
				$calls[] = ['depublish', $publication, $reason, $depublishedBy, $channels, $now];
				return ['publication' => ($publication['id'] ?? null), 'reason' => $reason, 'by' => $depublishedBy, 'channels' => $channels];
			}
		);
		$depublisher->method('outstandingChannels')->willReturnCallback(
			static function (array $depublication) use (&$calls, $case): array {
				$calls[] = ['outstandingChannels', $depublication];
				return ($case['outstanding'] ?? []);
			}
		);

		return $depublisher;

	}//end depublisher()

	/**
	 * A container double with the app config, or without it, and a file service.
	 *
	 * @param array<string, mixed>          $case  The case.
	 * @param array<int, array<int, mixed>> $calls The ordered calls.
	 *
	 * @return ContainerInterface The double.
	 */
	private function container(array $case, array &$calls): ContainerInterface {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $app . '/' . $key);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($config, $case, &$calls) {
				if ($id === IAppConfig::class && ($case['appConfig'] ?? true) === true) {
					return $config;
				}

				if ($id === 'OCA\\OpenRegister\\Service\\FileService') {
					return $this->fileService(case: $case, calls: $calls);
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		return $container;

	}//end container()

	/**
	 * A file service double that records unpublishFile() into the call log.
	 *
	 * @param array<string, mixed>          $case  The case.
	 * @param array<int, array<int, mixed>> $calls The ordered calls.
	 *
	 * @return object The double.
	 */
	private function fileService(array $case, array &$calls): object {
		$record = static function (array $entry) use (&$calls): void {
			$calls[] = $entry;
		};

		return new class($record, ($case['unpublishFails'] ?? false)) {
			/**
			 * @param \Closure $record Appends to the call log.
			 * @param bool     $fails  Whether unpublishing fails.
			 */
			public function __construct(private \Closure $record, private bool $fails) {
			}

			/**
			 * Record the unpublish, or fail it.
			 */
			public function unpublishFile(object $object, int $filePath): void {
				($this->record)(['unpublishFile', $object->jsonSerialize(), $filePath]);
				if ($this->fails === true) {
					throw new \RuntimeException('file gone');
				}
			}
		};

	}//end fileService()

	/**
	 * An OpenRegister reader double over a recording ObjectService.
	 *
	 * @param array<string, mixed>          $case  The case.
	 * @param array<int, array<int, mixed>> $calls The ordered calls.
	 *
	 * @return ServiceCatalogueServiceInterface The double.
	 */
	private function objects(array $case, array &$calls): ServiceCatalogueServiceInterface {
		$found = (array_key_exists('find', $case) === true ? $case['find'] : ['id' => 'pub-1', 'title' => 'Plan']);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (...$args) use (&$calls, $found): ?ObjectEntity {
				$calls[] = ['find', self::passedArguments(method: 'find', args: $args)];
				if (is_string($found) === true) {
					throw self::failure(kind: $found);
				}

				return ($found === null ? null : $this->entity(properties: $found));
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$calls, $case): ObjectEntity {
				$named = self::passedArguments(method: 'saveObject', args: $args);
				$calls[] = ['saveObject', $named];
				$fails = ($case['saveFails'][$named['schema'] ?? ''] ?? null);
				if ($fails !== null) {
					throw self::failure(kind: $fails);
				}

				return $this->entity(properties: ['saved' => $named['object']]);
			}
		);

		$objects = $this->createMock(ServiceCatalogueServiceInterface::class);
		$objects->method('getObjectService')->willReturn($objectService);

		return $objects;

	}//end objects()

	/**
	 * An ObjectEntity double whose jsonSerialize answers the given properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return ObjectEntity The double.
	 */
	private function entity(array $properties): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['jsonSerialize'])->getMock();
		$entity->method('jsonSerialize')->willReturn($properties);

		return $entity;

	}//end entity()

	/**
	 * The exception for a failure kind.
	 *
	 * @param string $kind missing, denied or boom.
	 *
	 * @return \Throwable The exception.
	 */
	private static function failure(string $kind): \Throwable {
		return match ($kind) {
			'missing' => new GoldenDoesNotExistException('gone'),
			'denied' => new GoldenNotAuthorizedException('no right'),
			default => new \RuntimeException('boom'),
		};

	}//end failure()

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

/**
 * Stands in for OpenRegister's DoesNotExistException; the controller matches on the class name's end.
 */
class GoldenDoesNotExistException extends \RuntimeException {
}//end class

/**
 * Stands in for OpenRegister's NotAuthorizedException; the controller matches on the class name's end.
 */
class GoldenNotAuthorizedException extends \RuntimeException {
}//end class
