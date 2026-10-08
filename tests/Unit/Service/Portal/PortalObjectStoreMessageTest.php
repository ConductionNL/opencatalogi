<?php

/**
 * Tests for writing a message into the resident's portal inbox.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

use OCA\OpenCatalogi\Service\Portal\PortalObjectStore;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for PortalObjectStore::writePortalMessage.
 */
class PortalObjectStoreMessageTest extends TestCase {

	/**
	 * A store over a fake ObjectService that records its saves and answers
	 * with the given result.
	 *
	 * @param mixed                            $result The saveObject answer.
	 * @param array<int, array<string, mixed>> $calls  The calls, filled in.
	 *
	 * @return PortalObjectStore
	 */
	private function store(mixed $result, array &$calls): PortalObjectStore {
		$service = new class($result, $calls) {
			/**
			 * @param mixed                            $result The answer.
			 * @param array<int, array<string, mixed>> $calls  The calls.
			 */
			public function __construct(private mixed $result, private array &$calls) {
			}

			public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy): mixed {
				$this->calls[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		return new PortalObjectStore(
			container: $container,
			publications: new PublicationQueryService(container: $this->createMock(ContainerInterface::class)),
			logger: new NullLogger(),
			appManager: $apps
		);
	}

	/**
	 * The message goes to portaliq's portalMessage schema, as the system.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	public function testTheMessageIsSavedInPortaliqsInboxAsTheSystem(): void {
		$calls = [];
		$this->store(['@self' => ['id' => 'm1']], $calls)->writePortalMessage(['subjectRef' => 'subject-1', 'subject' => 'Onderwerp']);

		$this->assertSame(
			[['object' => ['subjectRef' => 'subject-1', 'subject' => 'Onderwerp'], 'register' => 'portaliq', 'schema' => 'portalMessage', 'rbac' => false, 'multitenancy' => false]],
			$calls
		);
	}

	/**
	 * A save that answers nothing is a failure, so the caller does not move on.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	public function testAnEmptyAnswerThrows(): void {
		$calls = [];
		$this->expectException(RuntimeException::class);
		$this->store(null, $calls)->writePortalMessage(['subjectRef' => 'subject-1', 'subject' => 'Onderwerp']);
	}
}
