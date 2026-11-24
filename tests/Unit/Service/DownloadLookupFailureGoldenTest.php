<?php

/**
 * Golden test for how `DownloadService` answers a failed publication lookup.
 *
 * The method getPublicationData() turns three kinds of lookup failure into a
 * 500 that carries the exception message, answers a DoesNotExistException with
 * the 404 of DWN-OR-005, and lets any other exception through. Pinned for every kind.
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
 *
 * @spec openspec/specs/download-service/spec.md
 * @spec openspec/changes/archive/2026-11-24-download-service/tasks.md#7.2
 *
 * @covers \OCA\OpenCatalogi\Service\DownloadService
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Golden test for DownloadService's lookup failures.
 */
class DownloadLookupFailureGoldenTest extends TestCase {

	/**
	 * Every handled failure is a 500 with the message, a missing publication a 404;
	 * anything else propagates.
	 *
	 * @return void
	 */
	public function testLookupFailures(): void {
		$failures = [
			'not found' => new class('nf') extends \Exception implements NotFoundExceptionInterface {
			},
			'container' => new class('ce') extends \Exception implements ContainerExceptionInterface {
			},
			'multiple' => new MultipleObjectsReturnedException('mo'),
		];
		$service = new DownloadService($this->createMock(FileService::class), $this->passthroughL10n());
		$data = new ReflectionMethod($service, 'getPublicationData');
		$calls = [
			'getPublicationData' => static fn (ObjectService $objects): mixed => $data->invoke($service, 'p-1', $objects),
		];

		foreach ($calls as $method => $call) {
			foreach ($failures as $kind => $failure) {
				$response = $call($this->failingStore($failure));
				$this->assertInstanceOf(JSONResponse::class, $response, $method . ' ' . $kind);
				$this->assertSame([500, ['error' => $failure->getMessage()]], [$response->getStatus(), $response->getData()], $method . ' ' . $kind);
			}

			$missing = $call($this->failingStore(new DoesNotExistException('dne')));
			$this->assertInstanceOf(JSONResponse::class, $missing, $method . ' does not exist');
			$this->assertSame([404, ['error' => 'Publication not found']], [$missing->getStatus(), $missing->getData()], $method . ' does not exist');

			try {
				$call($this->failingStore(new RuntimeException('other')));
				$this->fail($method . ' swallowed an unhandled exception');
			} catch (RuntimeException $e) {
				$this->assertSame('other', $e->getMessage());
			}
		}

	}//end testLookupFailures()

	/**
	 * A store whose find() throws.
	 *
	 * @param Throwable $failure What find() throws.
	 *
	 * @return ObjectService
	 */
	private function failingStore(Throwable $failure): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException($failure);
		return $objects;
	}//end failingStore()

	/**
	 * An IL10N that answers the English text unchanged.
	 *
	 * @return \OCP\IL10N The translator.
	 */
	private function passthroughL10n(): \OCP\IL10N {
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		return $l10n;
	}
}//end class
