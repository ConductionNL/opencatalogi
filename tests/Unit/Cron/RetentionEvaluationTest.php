<?php
/**
 * Unit tests for the RetentionEvaluation cron job.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Cron;

use OCA\OpenCatalogi\Cron\RetentionEvaluation;
use OCA\OpenCatalogi\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Cron\RetentionEvaluation
 */
class RetentionEvaluationTest extends TestCase
{

    private RetentionEvaluation $job;

    private RetentionService $retentionService;

    private LoggerInterface $logger;

    private ITimeFactory $timeFactory;


    protected function setUp(): void
    {
        parent::setUp();
        $this->timeFactory      = $this->createMock(ITimeFactory::class);
        $this->retentionService = $this->createMock(RetentionService::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->job = new RetentionEvaluation(
            $this->timeFactory,
            $this->retentionService,
            $this->logger
        );

    }//end setUp()


    public function testRunInvokesEvaluate(): void
    {
        $this->retentionService->expects($this->once())
            ->method('evaluate')
            ->willReturn(['expiringSoon' => 1, 'reviewRequired' => 0, 'depublished' => 2, 'archived' => 0]);

        $this->logger->expects($this->once())->method('info');

        $method = new \ReflectionMethod(RetentionEvaluation::class, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, []);

    }//end testRunInvokesEvaluate()


    public function testRunSwallowsAndLogsExceptions(): void
    {
        $this->retentionService->method('evaluate')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())->method('error');

        $method = new \ReflectionMethod(RetentionEvaluation::class, 'run');
        $method->setAccessible(true);
        // Must not throw — failures are swallowed and logged.
        $method->invoke($this->job, []);

    }//end testRunSwallowsAndLogsExceptions()
}//end class
