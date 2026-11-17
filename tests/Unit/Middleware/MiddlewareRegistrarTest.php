<?php

/**
 * Tests for MiddlewareRegistrar: which middleware the app registers, and in which order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Middleware;

use OCA\OpenCatalogi\Middleware\ApiContractMiddleware;
use OCA\OpenCatalogi\Middleware\MiddlewareRegistrar;
use OCA\OpenCatalogi\Middleware\NoIndexMiddleware;
use OCA\OpenCatalogi\Middleware\StagingMiddleware;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiddlewareRegistrar::class)]
class MiddlewareRegistrarTest extends TestCase {

	public function testEveryMiddlewareIsRegisteredOnceInOrder(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->expects(self::exactly(3))
			->method('registerMiddleware')
			->willReturnCallback(
				static function (string $class) use (&$registered): void {
					$registered[] = $class;
				}
			);
		$context->expects(self::never())->method('registerService');

		(new MiddlewareRegistrar())->register($context);

		self::assertSame(
			[
				ApiContractMiddleware::class,
				NoIndexMiddleware::class,
				StagingMiddleware::class,
			],
			$registered
		);
	}
}
