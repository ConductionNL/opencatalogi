<?php

/**
 * Tests for `DELETE /api/publications/{id}/draft`.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\DraftPurgeServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Controller\DraftPurgeController;
use OCA\OpenCatalogi\Exception\DraftPurgeFailedException;
use OCA\OpenCatalogi\Exception\DraftPurgeRefusedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The route, the guards in order, and each outcome of the purge.
 *
 * @covers \OCA\OpenCatalogi\Controller\DraftPurgeController
 * @covers \OCA\OpenCatalogi\Exception\DraftPurgeRefusedException
 * @covers \OCA\OpenCatalogi\Exception\DraftPurgeFailedException
 */
class DraftPurgeControllerTest extends TestCase {

	/** Whether a user is logged in. */
	private bool $loggedIn = true;

	/** What find() does: an object, null, or a Throwable to throw. */
	private mixed $found;

	/** Whether the caller may update the publication. */
	private bool $mayUpdate = true;

	/** What purge() does: a removed list, or a Throwable to throw. */
	private mixed $outcome = [];

	/** How many times purge() ran. */
	private int $purges = 0;

	/** @var array<string, mixed> The arguments of the last find(). */
	public array $findCall = [];

	protected function setUp(): void {
		parent::setUp();
		$this->found = new \stdClass();
	}//end setUp()

	/**
	 * What the find double answers.
	 *
	 * @return object|null
	 */
	public function found(): ?object {
		if ($this->found instanceof \Throwable) {
			throw $this->found;
		}

		return $this->found;
	}//end found()

	/**
	 * The controller wired to the doubles.
	 *
	 * @return DraftPurgeController
	 */
	private function controller(): DraftPurgeController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->loggedIn === true ? $this->createMock(IUser::class) : null);

		$rights = $this->createMock(PublicationRightsInterface::class);
		$rights->method('mayUpdate')->willReturnCallback(fn (): bool => $this->mayUpdate);

		$purger = $this->createMock(DraftPurgeServiceInterface::class);
		$purger->method('purge')->willReturnCallback(
			function (): array {
				$this->purges++;
				if ($this->outcome instanceof \Throwable) {
					throw $this->outcome;
				}

				return $this->outcome;
			}
		);

		$test = $this;
		$objects = new class ($test) {
			public function __construct(private readonly DraftPurgeControllerTest $test) {
			}

			public function find(string $id, ?object $options = null, bool $_rbac = true): ?object {
				$this->test->findCall = ['id' => $id, 'rbac' => $_rbac, 'register' => $options?->register, 'schema' => $options?->schema];
				return $this->test->found();
			}
		};

		$openRegister = $this->createMock(OpenRegisterServicesInterface::class);
		$openRegister->method('registerConfiguration')->willReturn(['register' => 'reg', 'schema' => 'pub']);
		$openRegister->method('installedObjectService')->willReturn($objects);

		return new DraftPurgeController(
			appName: 'opencatalogi',
			request: $this->createMock(IRequest::class),
			l10n: $l10n,
			userSession: $session,
			rights: $rights,
			purger: $purger,
			openRegister: $openRegister
		);
	}//end controller()

	public function testTheRouteReachesDestroy(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$matches = array_values(
			array_filter(
				$routes['routes'],
				static fn (array $route): bool => $route['url'] === '/api/publications/{id}/draft' && $route['verb'] === 'DELETE'
			)
		);

		$this->assertCount(1, $matches);
		$this->assertSame('draftPurge#destroy', $matches[0]['name']);
		$this->assertTrue(method_exists(DraftPurgeController::class, 'destroy'));
	}//end testTheRouteReachesDestroy()

	public function testADraftIsPurgedAndTheRemovedListAnswered(): void {
		$this->outcome = [['kind' => 'publication', 'id' => 'pub-1', 'name' => 'Draft']];

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['removed' => $this->outcome], $response->getData());
		$this->assertSame(['id' => 'pub-1', 'rbac' => true, 'register' => 'reg', 'schema' => 'pub'], $this->findCall, 'Found with the caller\'s rights.');
	}//end testADraftIsPurgedAndTheRemovedListAnswered()

	public function testWithoutASessionNothingIsRead(): void {
		$this->loggedIn = false;

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->findCall);
		$this->assertSame(0, $this->purges);
	}//end testWithoutASessionNothingIsRead()

	public function testAPublicationTheCallerCannotReadAnswers404(): void {
		$this->found = new DoesNotExistException('not there or not readable');

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(0, $this->purges);
	}//end testAPublicationTheCallerCannotReadAnswers404()

	public function testAMissingPublicationAnswers404(): void {
		$this->found = null;

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->destroy(id: 'pub-1')->getStatus());
	}//end testAMissingPublicationAnswers404()

	public function testAnUnreachableRegisterAnswers503(): void {
		$this->found = new RuntimeException('database down');

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(0, $this->purges);
	}//end testAnUnreachableRegisterAnswers503()

	public function testWithoutTheUpdateRightNothingIsPurged(): void {
		$this->mayUpdate = false;

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $this->purges);
	}//end testWithoutTheUpdateRightNothingIsPurged()

	public function testAnEarlierReleaseAnswers409NamingIt(): void {
		$this->outcome = new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::RELEASED, evidence: '2026-10-01T09:00:00+00:00');

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('released', $response->getData()['error']);
		$this->assertSame('2026-10-01T09:00:00+00:00', $response->getData()['evidence']);
		$this->assertSame('This publication was published before, so it cannot be deleted permanently.', $response->getData()['message']);
	}//end testAnEarlierReleaseAnswers409NamingIt()

	public function testEachRefusalHasItsSentence(): void {
		$this->outcome = new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::NOT_A_DRAFT, evidence: 'published');
		$this->assertSame(
			'Only a draft or a publication in review can be deleted permanently.',
			$this->controller()->destroy(id: 'pub-1')->getData()['message']
		);

		$this->outcome = new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'audit-trail');
		$this->assertSame(
			'It cannot be checked that this publication was never published, so nothing was deleted.',
			$this->controller()->destroy(id: 'pub-1')->getData()['message']
		);
	}//end testEachRefusalHasItsSentence()

	public function testAFailurePartWayAnswers500NamingWhatWent(): void {
		$went = [['kind' => 'file', 'id' => '11', 'name' => 'decision.pdf']];
		$this->outcome = new DraftPurgeFailedException(removed: $went, failedOn: ['kind' => 'file', 'id' => '12', 'name' => 'annex.pdf']);

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('purge-incomplete', $response->getData()['error']);
		$this->assertSame($went, $response->getData()['removed']);
		$this->assertSame('12', $response->getData()['failedOn']['id']);
		$this->assertTrue($response->getData()['publicationKept']);
	}//end testAFailurePartWayAnswers500NamingWhatWent()
}//end class
