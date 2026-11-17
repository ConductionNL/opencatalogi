<?php

/**
 * Tests for the `ServiceBindings` table.
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
 * @covers \OCA\OpenCatalogi\Service\ServiceBindings
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Contract\AttachmentReadingQueueInterface;
use OCA\OpenCatalogi\Contract\AttachmentReadingStoreInterface;
use OCA\OpenCatalogi\Contract\RedactedOriginalGuardInterface;
use OCA\OpenCatalogi\Service\ServiceBindings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ServiceBindings.
 */
class ServiceBindingsTest extends TestCase {

	/**
	 * Every key is an interface and every value a concrete class that implements it.
	 *
	 * @return void
	 */
	public function testEveryBindingIsAnInterfaceBoundToAClassThatImplementsIt(): void {
		$this->assertNotEmpty(actual: ServiceBindings::INTERFACE_BINDINGS);

		foreach (ServiceBindings::INTERFACE_BINDINGS as $interface => $implementation) {
			$this->assertTrue(condition: interface_exists($interface), message: $interface . ' is not an interface');
			$this->assertTrue(condition: class_exists($implementation), message: $implementation . ' is not a class');
			$this->assertTrue(condition: (new \ReflectionClass($implementation))->isInstantiable(), message: $implementation . ' cannot be instantiated');
			$this->assertTrue(condition: is_a($implementation, $interface, true), message: $implementation . ' does not implement ' . $interface);
		}
	}//end testEveryBindingIsAnInterfaceBoundToAClassThatImplementsIt()

	/**
	 * The attachment reading contracts are each bound to their own implementation.
	 *
	 * @return void
	 */
	public function testTheAttachmentReadingContractsAreBoundToTheirOwnImplementation(): void {
		$bindings = ServiceBindings::INTERFACE_BINDINGS;

		$this->assertSame(
			expected: 'OCA\\OpenCatalogi\\BackgroundJob\\AttachmentReadingQueue',
			actual: $bindings[AttachmentReadingQueueInterface::class]
		);
		$this->assertSame(
			expected: 'OCA\\OpenCatalogi\\Service\\AttachmentReading\\AttachmentReadingStore',
			actual: $bindings[AttachmentReadingStoreInterface::class]
		);
		$this->assertSame(
			expected: 'OCA\\OpenCatalogi\\Service\\AttachmentReading\\RedactedOriginalGuard',
			actual: $bindings[RedactedOriginalGuardInterface::class]
		);
	}//end testTheAttachmentReadingContractsAreBoundToTheirOwnImplementation()
}//end class
