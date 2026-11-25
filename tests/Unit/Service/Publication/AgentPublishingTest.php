<?php

/**
 * AgentPublishing test: an agent publishes only what a human approved, through the publish button's checks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Service\Publication\AgentPublishing;
use OCA\OpenCatalogi\Service\Publication\PublishTransition;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The service over doubles of OpenRegister's object service, the rights, the state service and the transition.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
#[CoversClass(AgentPublishing::class)]
class AgentPublishingTest extends TestCase {

	/**
	 * The stored publications by id: `status`, and the test's own `_documents`, `_embargo`, `_mayUpdate`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The transitions run: id and the stored state passed.
	 *
	 * @var list<array{id: string, stored: string, date: string}>
	 */
	private array $published = [];

	/**
	 * The find calls, with their RBAC flag.
	 *
	 * @var list<array{id: string, rbac: bool}>
	 */
	private array $finds = [];

	/**
	 * The service with a caller who may publish or not, and an engine that may fail.
	 *
	 * @param bool   $mayPublish   Whether the caller is a publisher.
	 * @param string $configured   The configured register and schema.
	 * @param bool   $engineFails  Whether the lifecycle engine refuses.
	 *
	 * @return AgentPublishing
	 */
	private function service(bool $mayPublish = true, string $configured = 'publication', bool $engineFails = false): AgentPublishing {
		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param AgentPublishingTest $test The test holding the stored rows.
			 */
			public function __construct(private AgentPublishingTest $test) {
			}

			/**
			 * @param string $id      The id.
			 * @param mixed  $options The register and schema.
			 * @param bool   $_rbac   Whether RBAC applies.
			 *
			 * @return object
			 */
			public function find(string $id, mixed $options, bool $_rbac = false): object {
				return $this->test->found(id: $id, rbac: $_rbac);
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$config = $this->createConfiguredStub(originalClassName: IAppConfig::class, configuration: ['getValueString' => $configured]);
		$session = $this->createConfiguredStub(originalClassName: IUserSession::class, configuration: ['getUser' => $this->createStub(IUser::class)]);

		$rights = $this->createMock(originalClassName: PublicationRightsInterface::class);
		$rights->method('mayPublish')->willReturn($mayPublish);
		$rights->method('mayUpdate')->willReturnCallback(static fn (object $publication): bool => (($publication->_mayUpdate ?? true) === true));

		$states = $this->createMock(originalClassName: PublicationStateServiceInterface::class);
		$states->method('publishedRecord')->willReturnCallback(
			static fn (array $publication): array => array_merge($publication, ['publicationDate' => '2026-11-25T00:00:00+00:00'])
		);

		$publisher = $this->createMock(originalClassName: PublishTransition::class);
		$publisher->method('embargoVeto')->willReturnCallback(
			static fn (array $stored): ?array => (($stored['_embargo'] ?? false) === true) ? ['code' => 'embargo-holds', 'property' => 'publicationDate', 'embargoUntil' => null] : null
		);
		$publisher->method('documentCount')->willReturnCallback(static fn (string $id, ?object $publication): int => (int)($publication->_documents ?? 1));
		$publisher->method('publish')->willReturnCallback(
			function (string $id, string $stored, string $publicationDate) use ($engineFails): object {
				if ($engineFails === true) {
					throw new RuntimeException('the engine refused the move');
				}

				$this->published[] = ['id' => $id, 'stored' => $stored, 'date' => $publicationDate];
				return new \stdClass();
			}
		);

		return new AgentPublishing(container: $container, config: $config, userSession: $session, rights: $rights, states: $states, publisher: $publisher);
	}//end service()

	/**
	 * What the object service answers for an id: the stored row, or not found.
	 *
	 * @param string $id   The id.
	 * @param bool   $rbac Whether RBAC was asked for.
	 *
	 * @return object The publication.
	 *
	 * @throws RuntimeException When it is not stored.
	 */
	public function found(string $id, bool $rbac): object {
		$this->finds[] = ['id' => $id, 'rbac' => $rbac];
		if (isset($this->stored[$id]) === false) {
			throw new RuntimeException('DoesNotExistException');
		}

		return (object)$this->stored[$id];
	}//end found()

	/**
	 * Only an approved publication is published, and with the publish transition as the caller.
	 *
	 * @return void
	 */
	public function testOnlyAnApprovedPublicationIsPublished(): void {
		$this->stored = [
			'approved' => ['status' => 'approved'],
			'draft' => ['status' => 'draft'],
			'empty' => ['status' => ''],
			'review' => ['status' => 'in_review'],
			'public' => ['status' => 'published'],
		];

		$answer = $this->service()->publishApproved(ids: ['approved', 'draft', 'empty', 'review', 'public']);

		$this->assertSame(expected: ['approved'], actual: $answer['published']);
		$this->assertSame(
			expected: [
				['id' => 'draft', 'code' => 'not_approved'],
				['id' => 'empty', 'code' => 'not_approved'],
				['id' => 'review', 'code' => 'not_approved'],
				['id' => 'public', 'code' => 'not_approved'],
			],
			actual: $answer['refused']
		);
		$this->assertSame(expected: [['id' => 'approved', 'stored' => 'approved', 'date' => '2026-11-25T00:00:00+00:00']], actual: $this->published);
		$this->assertSame(expected: [true], actual: array_unique(array_column($this->finds, 'rbac')), message: 'every read is as the caller, with RBAC');
	}//end testOnlyAnApprovedPublicationIsPublished()

	/**
	 * The publish button's checks refuse an item each, and nothing is written for it.
	 *
	 * @return void
	 */
	public function testThePublishButtonsChecksRefuseItemByItem(): void {
		$this->stored = [
			'unreadable-elsewhere' => ['status' => 'approved', '_mayUpdate' => false],
			'embargoed' => ['status' => 'approved', '_embargo' => true],
			'empty' => ['status' => 'approved', '_documents' => 0],
		];

		$answer = $this->service()->publishApproved(ids: ['unreadable-elsewhere', 'embargoed', 'empty', 'missing']);

		$this->assertSame(expected: [], actual: $answer['published']);
		$this->assertSame(
			expected: [
				['id' => 'unreadable-elsewhere', 'code' => 'forbidden'],
				['id' => 'embargoed', 'code' => 'embargo_holds'],
				['id' => 'empty', 'code' => 'no_documents'],
				['id' => 'missing', 'code' => 'not_found'],
			],
			actual: $answer['refused']
		);
		$this->assertSame(expected: [], actual: $this->published);
	}//end testThePublishButtonsChecksRefuseItemByItem()

	/**
	 * A caller who may not publish is refused as a whole before anything is read; so is an unconfigured register.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotPublishIsRefusedAsAWhole(): void {
		$this->stored = ['approved' => ['status' => 'approved']];

		$this->assertSame(expected: ['error' => 'not_a_publisher'], actual: $this->service(mayPublish: false)->publishApproved(ids: ['approved']));
		$this->assertSame(expected: ['error' => 'not_configured'], actual: $this->service(configured: '')->publishApproved(ids: ['approved']));
		$this->assertSame(expected: [], actual: $this->finds);
		$this->assertSame(expected: [], actual: $this->published);
	}//end testACallerWhoMayNotPublishIsRefusedAsAWhole()

	/**
	 * A move the lifecycle engine refuses is answered for that item; the next item still goes.
	 *
	 * @return void
	 */
	public function testAnEngineRefusalIsAnsweredPerItem(): void {
		$this->stored = ['a' => ['status' => 'approved'], 'b' => ['status' => 'approved']];

		$answer = $this->service(engineFails: true)->publishApproved(ids: ['a', 'b']);

		$this->assertSame(expected: [], actual: $answer['published']);
		$this->assertSame(expected: [['id' => 'a', 'code' => 'not_published'], ['id' => 'b', 'code' => 'not_published']], actual: $answer['refused']);
	}//end testAnEngineRefusalIsAnsweredPerItem()
}//end class
