<?php
/**
 * Unit tests for the RecomputeCatalogAccess repair step.
 *
 * The step writes the staff access of every catalogue onto the schemas it
 * covers once per upgrade. These tests pin that it does nothing without
 * OpenRegister and otherwise hands every covered schema to one recompute.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Contract\CatalogAccessServiceInterface;
use OCA\OpenCatalogi\Repair\RecomputeCatalogAccess;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RecomputeCatalogAccess.
 */
class RecomputeCatalogAccessTest extends TestCase {

	public function testWithoutOpenRegisterNothingIsRecomputed(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('openregister')->willReturn(false);
		$access = $this->createMock(CatalogAccessServiceInterface::class);
		$access->expects($this->never())->method('recomputeMany');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new RecomputeCatalogAccess($apps, $access))->run($output);

	}//end testWithoutOpenRegisterNothingIsRecomputed()

	public function testEveryCoveredSchemaIsRecomputedInOneCall(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$access = $this->createMock(CatalogAccessServiceInterface::class);
		$access->method('coveredSchemaIds')->willReturn([173, 184]);
		$access->expects($this->once())->method('recomputeMany')->with([173, 184])->willReturn(['written' => 1, 'failed' => []]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Catalogue staff access written onto 1 schema(s)');
		$output->expects($this->never())->method('warning');

		(new RecomputeCatalogAccess($apps, $access))->run($output);

	}//end testEveryCoveredSchemaIsRecomputedInOneCall()

	public function testSchemasThatCouldNotBeWrittenAreReported(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$access = $this->createMock(CatalogAccessServiceInterface::class);
		$access->method('coveredSchemaIds')->willReturn([173, 184]);
		$access->method('recomputeMany')->willReturn(['written' => 1, 'failed' => [184]]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('184'));

		(new RecomputeCatalogAccess($apps, $access))->run($output);

	}//end testSchemasThatCouldNotBeWrittenAreReported()
}//end class
