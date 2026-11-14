<?php

/**
 * The contract with integriq's MappingExecutionRequestedEvent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WooIndexFieldMapper constructs integriq's event by position and calls its
 * accessors by name, behind class_exists; a rename on integriq's side would
 * compile here and fail at runtime. This pins the constructor's argument order
 * and the accessors this side calls, against the real class when integriq is
 * installed and against the verbatim test copy otherwise. integriq's own test of
 * the event: tests/Unit/Event/MappingExecutionRequestedEventTest.php.
 *
 * @category Test
 * @package  Unit\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md#requirement-woo-index-fields-come-from-the-editable-mapping-when-integriq-answers-req-wim-001
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\WooIndexFieldMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The event's shape as this side uses it.
 */
class WooIndexMappingContractTest extends TestCase {

	/**
	 * The constructor takes slug, input and source app first, in that order.
	 *
	 * @return void
	 */
	public function testTheConstructorTakesSlugInputAndSourceApp(): void {
		$parameters = (new ReflectionClass(WooIndexFieldMapper::EVENT_CLASS))->getConstructor()->getParameters();

		self::assertSame(['mappingSlug', 'input', 'sourceApp'], array_slice(array_map(static fn ($p): string => $p->getName(), $parameters), 0, 3));
		self::assertSame(['string', 'array', 'string'], array_map(static fn ($p): string => (string)$p->getType(), array_slice($parameters, 0, 3)));
		foreach (array_slice($parameters, 3) as $optional) {
			self::assertTrue($optional->isOptional(), $optional->getName() . ' must stay optional');
		}
	}//end testTheConstructorTakesSlugInputAndSourceApp()

	/**
	 * The accessors the mapper calls exist, with the return types it reads.
	 *
	 * @return void
	 */
	public function testTheAccessorsTheMapperCallsExist(): void {
		$class = new ReflectionClass(WooIndexFieldMapper::EVENT_CLASS);

		self::assertSame('?array', (string)$class->getMethod('getRefusal')->getReturnType());
		self::assertSame('bool', (string)$class->getMethod('isHandled')->getReturnType());
		self::assertSame('?array', (string)$class->getMethod('getOutput')->getReturnType());
		self::assertTrue($class->isSubclassOf(\OCP\EventDispatcher\Event::class));
	}//end testTheAccessorsTheMapperCallsExist()

}//end class
