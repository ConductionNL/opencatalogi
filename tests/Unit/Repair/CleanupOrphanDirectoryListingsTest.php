<?php
/**
 * Unit tests for the CleanupOrphanDirectoryListings repair step.
 *
 * The step is new in this change, ~300 lines, and shipped untested — part of
 * why CI reported "coverage dropped by 0.91% against the merge base" while
 * every test passed.
 *
 * A repair step is exactly the kind of code that must fail loudly in tests and
 * quietly in production: it runs during `occ upgrade`, so an exception here
 * aborts an upgrade. Every early return in run() is therefore deliberate, and
 * every one of them is indistinguishable from the others at runtime — no
 * return value, no exception. What separates them is which collaborator gets
 * touched, so that is what these tests assert on.
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\CleanupOrphanDirectoryListings;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for CleanupOrphanDirectoryListings.
 */
class CleanupOrphanDirectoryListingsTest extends TestCase
{

    private IAppManager|MockObject $appManager;

    private IDBConnection|MockObject $db;

    private ContainerInterface|MockObject $container;

    private CleanupOrphanDirectoryListings $step;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appManager = $this->createMock(IAppManager::class);
        $this->db         = $this->createMock(IDBConnection::class);
        $this->container  = $this->createMock(ContainerInterface::class);

        $this->step = new CleanupOrphanDirectoryListings(
            $this->appManager,
            $this->db,
            $this->container
        );

    }//end setUp()

    public function testGetNameDescribesTheCleanup(): void
    {
        $this->assertSame(
            'Clean up OpenCatalogi directory listings with a non-directory URL',
            $this->step->getName()
        );

    }//end testGetNameDescribesTheCleanup()

    /**
     * Without OpenRegister the step must warn and stop before touching the DB.
     *
     * The assertion is on the CONTAINER, not the warning text: the failure mode
     * that matters is the step trying to resolve OpenRegister services that are
     * not there, which on an upgrade would surface as a fatal rather than a
     * skipped repair.
     *
     * @return void
     */
    public function testSkipsEntirelyWhenOpenRegisterIsAbsent(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['opencatalogi']);
        $this->container->expects($this->never())->method('get');
        $this->db->expects($this->never())->method('getQueryBuilder');

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning');

        $this->step->run($output);

    }//end testSkipsEntirelyWhenOpenRegisterIsAbsent()

    /**
     * An unresolvable OpenRegister service is a skip, never a throw.
     *
     * This step runs inside `occ upgrade`. If it propagated, a half-installed
     * OpenRegister would abort the whole upgrade instead of leaving a few stale
     * listing rows behind — the cure being far worse than the disease is the
     * reason the guard exists.
     *
     * @return void
     */
    public function testAnUnresolvableServiceIsSkippedNotThrown(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['opencatalogi', 'openregister']);
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('SchemaMapper is not registered'));

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning');

        // The assertion is that run() returns at all.
        $this->step->run($output);

        $this->addToAssertionCount(1);

    }//end testAnUnresolvableServiceIsSkippedNotThrown()

    /**
     * With no listing schema there is nothing to clean, and nothing is queried.
     *
     * @return void
     */
    public function testNoListingSchemaMeansNoQuery(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['opencatalogi', 'openregister']);

        $schemaMapper = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findByApplicationAndSlug'])
            ->getMock();
        $schemaMapper->method('findByApplicationAndSlug')->willReturn(null);

        $this->container->method('get')->willReturn($schemaMapper);

        // The proof that it stopped: the per-register table scan never started.
        $this->db->expects($this->never())->method('getQueryBuilder');

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info');

        $this->step->run($output);

    }//end testNoListingSchemaMeansNoQuery()
}//end class
