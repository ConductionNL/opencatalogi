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
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Controller\DraftPurgeController;
use OCA\OpenCatalogi\Exception\DraftPurgeFailedException;
use OCA\OpenCatalogi\Exception\DraftPurgeRefusedException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
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

	/**
	 * What find() does: an object, null, or a Throwable to throw.
	 *
	 * @var mixed
	 */
	private mixed $found;

	/**
	 * Whether the caller may update the publication.
	 *
	 * @var bool
	 */
	private bool $mayUpdate = true;

	/**
	 * What purge() does: a removed list, or a Throwable to throw.
	 *
	 * @var mixed
	 */
	private mixed $outcome = [];

	/**
	 * How many times purge() ran.
	 *
	 * @var int
	 */
	private int $purges = 0;

	/**
	 * mixed> The arguments of the last find().
	 *
	 * @var array<string,
	 */
	public array $findCall = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->found = new \stdClass();
	}//end setUp()

	/**
	 * What the find double answers.
	 *
	 * @param string $id The publication id.
	 *
	 * @return object|null
	 */
	public function found(string $id): ?object {
		$this->findCall = ['id' => $id];
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
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$rights = $this->createMock(originalClassName: PublicationRightsInterface::class);
		$rights->method('mayUpdate')->willReturnCallback(fn (): bool => $this->mayUpdate);

		$purger = $this->createMock(originalClassName: DraftPurgeServiceInterface::class);
		$purger->method('find')->willReturnCallback(fn (string $id): ?object => $this->found(id: $id));
		$purger->method('purge')->willReturnCallback(
			function (): array {
				$this->purges++;
				if ($this->outcome instanceof \Throwable) {
					throw $this->outcome;
				}

				return $this->outcome;
			}
		);

		return new DraftPurgeController(
			request: $this->createMock(originalClassName: IRequest::class),
			l10n: $l10n,
			rights: $rights,
			purger: $purger
		);
	}//end controller()

	/**
	 * The route reaches destroy.
	 *
	 * @return void
	 */
	public function testTheRouteReachesDestroy(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$matches = array_values(
			array_filter(
				$routes['routes'],
				static fn (array $route): bool => $route['url'] === '/api/publications/{id}/draft' && $route['verb'] === 'DELETE'
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $matches);
		$this->assertSame(expected: 'draftPurge#destroy', actual: $matches[0]['name']);
		$this->assertTrue(condition: method_exists(DraftPurgeController::class, 'destroy'));
	}//end testTheRouteReachesDestroy()

	/**
	 * Adraft is purged and the removed list answered.
	 *
	 * @return void
	 */
	public function testADraftIsPurgedAndTheRemovedListAnswered(): void {
		$this->outcome = [['kind' => 'publication', 'id' => 'pub-1', 'name' => 'Draft']];

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['removed' => $this->outcome], actual: $response->getData());
		$this->assertSame(expected: ['id' => 'pub-1'], actual: $this->findCall);
	}//end testADraftIsPurgedAndTheRemovedListAnswered()

	/**
	 * Amissing publication answers404.
	 *
	 * @return void
	 */
	public function testAMissingPublicationAnswers404(): void {
		$this->found = null;

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller()->destroy(id: 'pub-1')->getStatus());
	}//end testAMissingPublicationAnswers404()

	/**
	 * An unreachable register answers503.
	 *
	 * @return void
	 */
	public function testAnUnreachableRegisterAnswers503(): void {
		$this->found = new RuntimeException('database down');

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertSame(expected: 0, actual: $this->purges);
	}//end testAnUnreachableRegisterAnswers503()

	/**
	 * Without the update right nothing is purged.
	 *
	 * @return void
	 */
	public function testWithoutTheUpdateRightNothingIsPurged(): void {
		$this->mayUpdate = false;

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: 0, actual: $this->purges);
	}//end testWithoutTheUpdateRightNothingIsPurged()

	/**
	 * An earlier release answers409 naming it.
	 *
	 * @return void
	 */
	public function testAnEarlierReleaseAnswers409NamingIt(): void {
		$this->outcome = new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::RELEASED, evidence: '2026-10-01T09:00:00+00:00');

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'released', actual: $response->getData()['error']);
		$this->assertSame(expected: '2026-10-01T09:00:00+00:00', actual: $response->getData()['evidence']);
		$this->assertSame(expected: 'This publication was published before, so it cannot be deleted permanently.', actual: $response->getData()['message']);
	}//end testAnEarlierReleaseAnswers409NamingIt()

	/**
	 * Each refusal has its sentence.
	 *
	 * @return void
	 */
	public function testEachRefusalHasItsSentence(): void {
		$this->outcome = new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::NOT_A_DRAFT, evidence: 'published');
		$this->assertSame(
			expected: 'Only a draft or a publication in review can be deleted permanently.',
			actual: $this->controller()->destroy(id: 'pub-1')->getData()['message']
		);

		$this->outcome = new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'audit-trail');
		$this->assertSame(
			expected: 'It cannot be checked that this publication was never published, so nothing was deleted.',
			actual: $this->controller()->destroy(id: 'pub-1')->getData()['message']
		);
	}//end testEachRefusalHasItsSentence()

	/**
	 * Afailure part way answers500 naming what went.
	 *
	 * @return void
	 */
	public function testAFailurePartWayAnswers500NamingWhatWent(): void {
		$went = [['kind' => 'file', 'id' => '11', 'name' => 'decision.pdf']];
		$this->outcome = new DraftPurgeFailedException(removed: $went, failedOn: ['kind' => 'file', 'id' => '12', 'name' => 'annex.pdf']);

		$response = $this->controller()->destroy(id: 'pub-1');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: 'purge-incomplete', actual: $response->getData()['error']);
		$this->assertSame(expected: $went, actual: $response->getData()['removed']);
		$this->assertSame(expected: '12', actual: $response->getData()['failedOn']['id']);
		$this->assertTrue(condition: $response->getData()['publicationKept']);
	}//end testAFailurePartWayAnswers500NamingWhatWent()
}//end class
