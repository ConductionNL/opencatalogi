<?php
/**
 * Unit tests for RetentionController.
 *
 * Covers the four routed retention endpoints that had no contract test of any
 * kind (gate-25): the dashboard queue summary, the per-catalog defaults
 * read-out, the human retention decision, and the CSV report export.
 *
 * RetentionService is mocked; only the controller's auth posture, parameter
 * marshalling and response shaping are under test here.
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\RetentionController;
use OCA\OpenCatalogi\Service\RetentionService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RetentionController.
 */
class RetentionControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private RetentionService|MockObject $retentionService;
    private IUserSession|MockObject $userSession;
    private RetentionController $controller;

    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->retentionService = $this->createMock(RetentionService::class);
        $this->userSession      = $this->createMock(IUserSession::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new RetentionController(
            'opencatalogi',
            $this->request,
            $this->retentionService,
            $l10n,
            $this->userSession
        );

    }//end setUp()

    /**
     * Report an authenticated user on the session.
     */
    private function authenticate(string $uid='admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end authenticate()

    /*
     * ------------------------------------------------------------------
     * queueSummary — GET /api/retention/queue
     * ------------------------------------------------------------------
     */

    public function testQueueSummaryAnonymousReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        // The service must not be consulted at all for an anonymous caller —
        // the retention queue counts describe publications this caller has no
        // standing to know about.
        $this->retentionService->expects($this->never())->method('getQueueSummary');

        $response = $this->controller->queueSummary();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(401, $response->getStatus());

    }//end testQueueSummaryAnonymousReturns401()

    public function testQueueSummaryReturnsServiceCounts(): void
    {
        $this->authenticate();
        $this->retentionService->expects($this->once())
            ->method('getQueueSummary')
            ->willReturn(['due' => 4, 'overdue' => 2, 'upcoming' => 9]);

        $response = $this->controller->queueSummary();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['due' => 4, 'overdue' => 2, 'upcoming' => 9], $response->getData());

    }//end testQueueSummaryReturnsServiceCounts()

    public function testQueueSummaryServiceFailureReturns500(): void
    {
        $this->authenticate();
        $this->retentionService->method('getQueueSummary')
            ->willThrowException(new \RuntimeException('register unavailable'));

        $response = $this->controller->queueSummary();

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('register unavailable', $response->getData()['error']);

    }//end testQueueSummaryServiceFailureReturns500()

    /*
     * ------------------------------------------------------------------
     * getDefaults — GET /api/retention/defaults
     * ------------------------------------------------------------------
     */

    public function testGetDefaultsReturnsDefaultsAndWarningWindow(): void
    {
        $this->retentionService->expects($this->once())
            ->method('getRetentionDefaults')
            ->willReturn(['woo-besluiten' => 120]);
        $this->retentionService->expects($this->once())
            ->method('getWarningWindowDays')
            ->willReturn(30);

        $response = $this->controller->getDefaults();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        // RET-004: retention terms are CONFIGURATION, so both halves of the
        // payload must come from the service rather than a hard-coded literal.
        $this->assertSame(['woo-besluiten' => 120], $data['defaults']);
        $this->assertSame(30, $data['warningWindowDays']);

    }//end testGetDefaultsReturnsDefaultsAndWarningWindow()

    public function testGetDefaultsServiceFailureReturns500(): void
    {
        $this->retentionService->method('getRetentionDefaults')
            ->willThrowException(new \RuntimeException('config unreadable'));

        $response = $this->controller->getDefaults();

        $this->assertSame(500, $response->getStatus());

    }//end testGetDefaultsServiceFailureReturns500()

    /*
     * ------------------------------------------------------------------
     * decide — POST /api/retention/publications/{id}/decision
     * ------------------------------------------------------------------
     */

    public function testDecidePassesDecisionRationaleAndExtensionToService(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => match ($key) {
                'decision'     => 'extend',
                'note'         => 'legal hold pending',
                'extendMonths' => '12',
                default        => $default,
            }
        );

        // The whole point of a human decision is that the rationale and the
        // extension length are recorded with it. Asserting only the status
        // code would pass even if the note were silently dropped.
        $this->retentionService->expects($this->once())
            ->method('recordHumanDecision')
            ->with(
                publicationId: 'pub-1',
                decision: 'extend',
                rationale: 'legal hold pending',
                extendMonths: 12
            )
            ->willReturn(['status' => 'extended', 'until' => '2027-08-09']);

        $response = $this->controller->decide('pub-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('extended', $response->getData()['status']);

    }//end testDecidePassesDecisionRationaleAndExtensionToService()

    public function testDecideDefaultsMissingParametersRatherThanGuessing(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $default
        );

        $this->retentionService->expects($this->once())
            ->method('recordHumanDecision')
            ->with(
                publicationId: 'pub-2',
                decision: '',
                rationale: '',
                extendMonths: 0
            )
            ->willReturn(['status' => 'rejected']);

        $response = $this->controller->decide('pub-2');

        $this->assertSame(200, $response->getStatus());

    }//end testDecideDefaultsMissingParametersRatherThanGuessing()

    public function testDecideRejectedByServiceReturns400(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $default
        );
        $this->retentionService->method('recordHumanDecision')
            ->willThrowException(new \InvalidArgumentException('unknown decision'));

        $response = $this->controller->decide('pub-3');

        // 400, not 500: an invalid decision is the caller's error.
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('unknown decision', $response->getData()['error']);

    }//end testDecideRejectedByServiceReturns400()

    /*
     * ------------------------------------------------------------------
     * exportReport — GET /api/retention/report
     * ------------------------------------------------------------------
     */

    public function testExportReportReturnsCsvDownloadWithFilters(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => match ($key) {
                'catalog' => 'woo',
                'from'    => '2026-01-01',
                'to'      => '2026-06-30',
                default   => $default,
            }
        );

        $rows = [['id' => 'pub-1', 'category' => 'woo-besluiten']];
        $this->retentionService->expects($this->once())
            ->method('buildReport')
            ->with(catalogSlug: 'woo', from: '2026-01-01', to: '2026-06-30')
            ->willReturn($rows);
        $this->retentionService->expects($this->once())
            ->method('renderReportCsv')
            ->with($rows)
            ->willReturn("id,category\npub-1,woo-besluiten\n");

        $response = $this->controller->exportReport();

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $this->assertStringContainsString('pub-1', $response->render());

    }//end testExportReportReturnsCsvDownloadWithFilters()

    public function testExportReportWithoutFiltersPassesNullsNotEmptyStrings(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $default
        );

        // An absent filter must reach the service as null. Coercing it to ''
        // would turn "every catalog" into "the catalog whose slug is empty",
        // which silently produces an empty accountability report.
        $this->retentionService->expects($this->once())
            ->method('buildReport')
            ->with(catalogSlug: null, from: null, to: null)
            ->willReturn([]);
        $this->retentionService->method('renderReportCsv')->willReturn('');

        $response = $this->controller->exportReport();

        $this->assertInstanceOf(DataDownloadResponse::class, $response);

    }//end testExportReportWithoutFiltersPassesNullsNotEmptyStrings()

    public function testExportReportServiceFailureReturns500(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $default
        );
        $this->retentionService->method('buildReport')
            ->willThrowException(new \RuntimeException('report failed'));

        $response = $this->controller->exportReport();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());

    }//end testExportReportServiceFailureReturns500()
}//end class
