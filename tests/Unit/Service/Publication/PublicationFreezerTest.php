<?php

/**
 * PublicationFreezerTest
 *
 * The freezer says "not frozen" with the reason whenever OpenRegister's archive
 * handler cannot freeze, and never throws (REQ-PWA-003).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublicationFreezer;
use OCA\OpenRegister\Service\Object\ObjectStateRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationFreezer
 */
final class PublicationFreezerTest extends TestCase {

	/**
	 * A freezer over a container that answers the archive handler with the given double.
	 *
	 * @param object|null $handler The handler, null for a container that cannot resolve it.
	 *
	 * @return PublicationFreezer The freezer.
	 */
	private function freezer(?object $handler): PublicationFreezer {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		if ($handler === null) {
			$container->method('get')->willThrowException(new RuntimeException(message: 'ArchiveHandler is not registered'));
			$logger->expects($this->once())->method('warning');

			return new PublicationFreezer(container: $container, logger: $logger);
		}

		$container->method('get')->with(PublicationFreezer::ARCHIVE_HANDLER)->willReturn($handler);
		$logger->expects($this->never())->method('warning');

		return new PublicationFreezer(container: $container, logger: $logger);
	}//end freezer()

	/**
	 * A handler that answers the given marker.
	 *
	 * @param array<string, mixed>|null $marker The frozen marker it answers.
	 *
	 * @return object The double.
	 */
	private function handler(?array $marker): object {
		return new class($marker) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $marker The marker.
			 */
			public function __construct(private readonly ?array $marker) {
			}

			/**
			 * The freeze, as ArchiveHandler answers it.
			 *
			 * @param string $identifier The object.
			 * @param string|null $reason Why.
			 *
			 * @return array{uuid: string, frozen: array<string, mixed>|null} The answer.
			 */
			public ?ObjectStateRequest $asked = null;

			/**
			 * OpenRegister's ArchiveHandlerInterface::freeze() signature.
			 *
			 * @param ObjectStateRequest $request The object and why.
			 * @param string|null        $state   The declaring state.
			 *
			 * @return array{uuid: string, frozen: array|null} The marker.
			 */
			public function freeze(ObjectStateRequest $request, ?string $state = null): array {
				$this->asked = $request;
				return ['uuid' => $request->identifier, 'frozen' => $this->marker];
			}
		};
	}//end handler()

	/**
	 * A stored marker is frozen.
	 *
	 * @return void
	 */
	public function testAStoredMarkerIsFrozen(): void {
		$answer = $this->freezer(handler: $this->handler(marker: ['reason' => 'x']))->freeze(publicationId: 'pub-1', reason: 'x');

		self::assertSame(expected: ['frozen' => true], actual: $answer);
	}//end testAStoredMarkerIsFrozen()

	/**
	 * An answer without a marker is not frozen, and says so.
	 *
	 * @return void
	 */
	/**
	 * The freeze names the publication and the reason in OpenRegister's request,
	 * unscoped to a register or schema; the old named-argument call failed live
	 * with "Unknown named parameter $identifier".
	 *
	 * @return void
	 */
	public function testTheFreezeAsksOpenRegisterWithAStateRequest(): void {
		$handler = $this->handler(marker: ['reason' => 'Persoonsgegevens']);
		$this->freezer(handler: $handler)->freeze(publicationId: 'pub-1', reason: 'Persoonsgegevens');

		self::assertInstanceOf(expected: ObjectStateRequest::class, actual: $handler->asked);
		self::assertSame(expected: 'pub-1', actual: $handler->asked->identifier);
		self::assertSame(expected: 'Persoonsgegevens', actual: $handler->asked->reason);
		self::assertNull(actual: $handler->asked->register);
		self::assertNull(actual: $handler->asked->schema);
	}//end testTheFreezeAsksOpenRegisterWithAStateRequest()

	public function testAnAnswerWithoutAMarkerIsNotFrozen(): void {
		$answer = $this->freezer(handler: $this->handler(marker: null))->freeze(publicationId: 'pub-1', reason: 'x');

		self::assertFalse(condition: $answer['frozen']);
		self::assertSame(expected: 'OpenRegister did not report the publication as frozen.', actual: $answer['frozenError']);
	}//end testAnAnswerWithoutAMarkerIsNotFrozen()

	/**
	 * A handler the container cannot resolve is logged and reported, never thrown.
	 *
	 * @return void
	 */
	public function testAnUnresolvableHandlerIsReportedNotThrown(): void {
		$answer = $this->freezer(handler: null)->freeze(publicationId: 'pub-1', reason: 'x');

		self::assertSame(expected: ['frozen' => false, 'frozenError' => 'ArchiveHandler is not registered'], actual: $answer);
	}//end testAnUnresolvableHandlerIsReportedNotThrown()

	/**
	 * A withdrawal that names no publication freezes nothing.
	 *
	 * @return void
	 */
	public function testNoPublicationIdFreezesNothing(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$freezer = new PublicationFreezer(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$answer = $freezer->freeze(publicationId: '', reason: 'x');

		self::assertSame(expected: ['frozen' => false, 'frozenError' => 'The withdrawal names no publication to freeze.'], actual: $answer);
	}//end testNoPublicationIdFreezesNothing()
}//end class
