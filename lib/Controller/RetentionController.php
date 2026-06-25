<?php
/**
 * OpenCatalogi Retention Controller.
 *
 * Officer- and admin-facing endpoints for the publication retention lifecycle:
 * the review-queue summary (dashboard widget), per-catalog retention defaults,
 * human disposal/extension decisions, and the retention report export. All write
 * surfaces are admin-gated (no @NoAdminRequired → NC SecurityMiddleware enforces
 * the admin gate by default) because they act on arbitrary publication ids through
 * the consumed OpenRegister ObjectService; the read-only queue summary is
 * authenticated and the report export is authenticated (RET-009). Per hydra
 * ADR-022 the controller holds no policy — it delegates to RetentionService, which
 * consumes the OpenRegister abstractions.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\RetentionService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for publication retention lifecycle operations.
 */
class RetentionController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName          The app name.
     * @param IRequest         $request          The request.
     * @param RetentionService $retentionService The retention service.
     * @param IL10N            $l10n             The localization service.
     * @param IUserSession     $userSession      The user session.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly RetentionService $retentionService,
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Retention review-queue summary counts for the dashboard widget.
     *
     * Read-only; authenticated. Counts are derived through the OpenRegister
     * ObjectService with RBAC enforced, so each caller only sees publications
     * they may read.
     *
     * @return JSONResponse The expiring-soon / review-required / archived counts.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
     */
    public function queueSummary(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->retentionService->getQueueSummary());
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end queueSummary()

    /**
     * Get the per-catalog retention defaults configuration.
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces the
     * admin gate); auditable via the delegated-admin system.
     *
     * @return JSONResponse The per-catalog category defaults and warning window.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function getDefaults(): JSONResponse
    {
        try {
            return new JSONResponse(
                [
                    'defaults'          => $this->retentionService->getRetentionDefaults(),
                    'warningWindowDays' => $this->retentionService->getWarningWindowDays(),
                ]
            );
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getDefaults()

    /**
     * Update the per-catalog retention defaults configuration.
     *
     * Admin-only; auditable via the delegated-admin system. Retention terms are
     * stored as configuration data — never hard-coded in PHP (RET-004).
     *
     * @return JSONResponse The stored defaults.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function setDefaults(): JSONResponse
    {
        try {
            $defaults = (array) ($this->request->getParam('defaults', []));
            $stored   = $this->retentionService->setRetentionDefaults($defaults);
            return new JSONResponse(['defaults' => $stored]);
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }

    }//end setDefaults()

    /**
     * Record a human retention decision (extend / depublish / archive / dispose).
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces the
     * admin gate). Acts on an arbitrary publication id, so it MUST NOT be exposed
     * to non-admins without a per-object guard; the admin gate is the guard. A
     * mandatory rationale is enforced in the service and the decision is persisted
     * through OpenRegister's immutable audit trail (RET-006/RET-007).
     *
     * @param string $id The publication uuid.
     *
     * @return JSONResponse The updated publication.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function decide(string $id): JSONResponse
    {
        try {
            $decision     = (string) $this->request->getParam('decision', '');
            $rationale    = (string) $this->request->getParam('note', '');
            $extendMonths = (int) $this->request->getParam('extendMonths', 0);
            $result       = $this->retentionService->recordHumanDecision(
                publicationId: $id,
                decision: $decision,
                rationale: $rationale,
                extendMonths: $extendMonths
            );
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }

    }//end decide()

    /**
     * Export the retention report as CSV (UTF-8 with BOM).
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces the
     * admin gate); this is an internal accountability surface, never public
     * (RET-009). Anonymous requests are rejected by the framework before this
     * method runs.
     *
     * @return DataDownloadResponse|JSONResponse The CSV download, or an error.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/publication-retention-lifecycle/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function exportReport(): DataDownloadResponse|JSONResponse
    {
        try {
            $catalog = $this->request->getParam('catalog', null);
            $from    = $this->request->getParam('from', null);
            $to      = $this->request->getParam('to', null);

            $catalogSlug = null;
            if ($catalog !== null) {
                $catalogSlug = (string) $catalog;
            }

            $fromValue = null;
            if ($from !== null) {
                $fromValue = (string) $from;
            }

            $toValue = null;
            if ($to !== null) {
                $toValue = (string) $to;
            }

            $rows = $this->retentionService->buildReport(
                catalogSlug: $catalogSlug,
                from: $fromValue,
                to: $toValue
            );
            $csv  = $this->retentionService->renderReportCsv($rows);

            return new DataDownloadResponse($csv, 'retention-report.csv', 'text/csv');
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try

    }//end exportReport()
}//end class
