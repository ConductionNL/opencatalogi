<?php

/**
 * Tests for LatestFlowRun: the latest run of one flow, as a system read.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\LatestFlowRun;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunViewer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\LatestFlowRun
 */
class LatestFlowRunTest extends TestCase {

	/**
	 * A container holding one run mapper.
	 *
	 * @param FlowRunMapper $runs The mapper.
	 *
	 * @return ContainerInterface
	 */
	private function container(FlowRunMapper $runs): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(FlowRunMapper::class)->willReturn($runs);

		return $container;
	}//end container()

	/**
	 * The first run of a one-row listing for the flow comes back, read with no requester.
	 *
	 * @return void
	 */
	public function testTheLatestRunOfTheFlowIsReturned(): void {
		$run = new FlowRun();
		$runs = $this->createMock(FlowRunMapper::class);
		$runs->expects($this->once())->method('findAllRuns')->willReturnCallback(
			function (FlowRunListing $listing, FlowRunViewer $viewer) use ($run): array {
				$this->assertSame('flow-1', $listing->filter->flowId);
				$this->assertSame(1, $listing->limit);
				$this->assertNull($viewer->requesterUid);
				return [$run];
			}
		);

		$this->assertSame($run, (new LatestFlowRun(container: $this->container(runs: $runs)))->find(flowUuid: 'flow-1'));
	}//end testTheLatestRunOfTheFlowIsReturned()

	/**
	 * A flow that never ran, or runs that cannot be read, give null.
	 *
	 * @return void
	 */
	public function testNoRunOrAFailedReadIsNull(): void {
		$none = $this->createMock(FlowRunMapper::class);
		$none->method('findAllRuns')->willReturn([]);
		$broken = $this->createMock(FlowRunMapper::class);
		$broken->method('findAllRuns')->willThrowException(new RuntimeException('no table'));

		$this->assertNull((new LatestFlowRun(container: $this->container(runs: $none)))->find(flowUuid: 'flow-1'));
		$this->assertNull((new LatestFlowRun(container: $this->container(runs: $broken)))->find(flowUuid: 'flow-1'));
	}//end testNoRunOrAFailedReadIsNull()
}//end class
