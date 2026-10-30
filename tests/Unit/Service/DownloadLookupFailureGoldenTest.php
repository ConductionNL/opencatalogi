<?php

/**
 * Golden test for how `DownloadService` answers a failed publication lookup.
 *
 * getPublicationData() and publicationAttachments() turn four kinds of lookup
 * failure into a 500 that carries the exception message, and let any other
 * exception through. Pinned for both methods and every kind.
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
	 * Every handled failure is a 500 with the message; anything else propagates.
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
			'does not exist' => new DoesNotExistException('dne'),
		];
		$service = new DownloadService($this->createMock(FileService::class));
		$data = new ReflectionMethod($service, 'getPublicationData');
		$calls = [
			'getPublicationData' => static fn (ObjectService $objects): mixed => $data->invoke($service, 'p-1', $objects),
			'publicationAttachments' => static fn (ObjectService $objects): mixed => $service->publicationAttachments('p-1', $objects),
		];

		foreach ($calls as $method => $call) {
			foreach ($failures as $kind => $failure) {
				$response = $call($this->failingStore($failure));
				$this->assertInstanceOf(JSONResponse::class, $response, $method . ' ' . $kind);
				$this->assertSame([500, ['error' => $failure->getMessage()]], [$response->getStatus(), $response->getData()], $method . ' ' . $kind);
			}

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
}//end class
