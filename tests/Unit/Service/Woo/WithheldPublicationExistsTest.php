<?php

/**
 * Unit tests for WithheldDocumentStore::publicationExists.
 *
 * Only OpenRegister saying the object does not exist answers false; every
 * other failure answers null, so a caller is not told a publication is gone
 * that a retry would find.
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

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for WithheldDocumentStore::publicationExists.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore
 * @uses \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class WithheldPublicationExistsTest extends TestCase {

	/** @var array<int, array<int, mixed>> */
	private array $warnings = [];

	/** @var array<int, array<string, mixed>> */
	private array $finds = [];

	/**
	 * The store over an object service.
	 *
	 * @param mixed $objects What the container resolves the ObjectService to.
	 * @param bool $installed Whether OpenRegister is installed.
	 * @param string $schema The configured publication schema.
	 *
	 * @return WithheldDocumentStore
	 */
	private function store(mixed $objects, bool $installed = true, string $schema = '7'): WithheldDocumentStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ($key) {
				'publication_register' => '5',
				'publication_schema' => $schema,
				default => $default,
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new WithheldDocumentStore($config, new OpenRegisterLookup($container, $appManager), $logger);
	}//end store()

	/**
	 * An ObjectService whose find answers with the given outcome.
	 *
	 * @param mixed $outcome The found value, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function objects(mixed $outcome): object {
		$finds = &$this->finds;
		return new class($outcome, $finds) {
			public function __construct(
				private mixed $outcome,
				private array &$finds,
			) {
			}

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->outcome instanceof \Throwable) {
					throw $this->outcome;
				}

				return $this->outcome;
			}
		};
	}//end objects()

	public function testAnExistingPublicationIsFoundOutsideRbacAndTenancy(): void {
		$this->assertTrue($this->store($this->objects(['id' => 'p1']))->publicationExists('p1'));
		$this->assertSame([['id' => 'p1', 'register' => '5', 'schema' => '7', 'rbac' => false, 'multitenancy' => false]], $this->finds);
	}//end testAnExistingPublicationIsFoundOutsideRbacAndTenancy()

	public function testABlankIdIsNoPublicationAndAsksNothing(): void {
		$this->assertFalse($this->store($this->objects(['id' => 'p1']))->publicationExists('  '));
		$this->assertSame([], $this->finds);
	}//end testABlankIdIsNoPublicationAndAsksNothing()

	public function testOnlyDoesNotExistAnswersFalse(): void {
		$this->assertFalse($this->store($this->objects(new DoesNotExistException('gone')))->publicationExists('p1'));
		$this->assertSame([], $this->warnings);
	}//end testOnlyDoesNotExistAnswersFalse()

	public function testAnEmptyAnswerIsNoPublication(): void {
		$this->assertFalse($this->store($this->objects([]))->publicationExists('p1'));
		$this->assertFalse($this->store($this->objects(null))->publicationExists('p1'));
	}//end testAnEmptyAnswerIsNoPublication()

	public function testAFailingLookupIsNoAnswerAndIsLogged(): void {
		$this->assertNull($this->store($this->objects(new RuntimeException('db down')))->publicationExists('p1'));
		$this->assertSame(
			[['WithheldDocuments: the publication could not be looked up', ['publication' => 'p1', 'exception' => 'db down']]],
			$this->warnings
		);
	}//end testAFailingLookupIsNoAnswerAndIsLogged()

	public function testAMissingSchemaConfigurationIsNoAnswer(): void {
		$this->assertNull($this->store($this->objects(['id' => 'p1']), schema: '')->publicationExists('p1'));
		$this->assertSame([], $this->finds);
		$this->assertCount(1, $this->warnings);
	}//end testAMissingSchemaConfigurationIsNoAnswer()

	public function testWithoutOpenRegisterThereIsNoAnswer(): void {
		$this->assertNull($this->store($this->objects(['id' => 'p1']), installed: false)->publicationExists('p1'));
		$this->assertSame([], $this->finds);
	}//end testWithoutOpenRegisterThereIsNoAnswer()

	public function testAnObjectServiceThatIsNotAnObjectIsNoAnswer(): void {
		$this->assertNull($this->store(null)->publicationExists('p1'));
		$this->assertSame([], $this->warnings);
	}//end testAnObjectServiceThatIsNotAnObjectIsNoAnswer()
}//end class
