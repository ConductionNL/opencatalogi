<?php
/**
 * OpenCatalogi WOO (Wet open overheid) Controller.
 *
 * Admin-facing endpoints for the WOO transparency workflow: create a disclosure
 * batch (provisioning a Deck board via the consumed deck leaf), assess a document
 * (moving its Deck card), read batch status + progress, list the weigeringsgronden
 * catalogue, generate the inventarislijst (CSV / archival HTML), mark a batch
 * ready for review, and publish it to a public reading room.
 *
 * Every write surface acts on arbitrary object ids through the consumed
 * OpenRegister ObjectService, so it is admin-gated: no @NoAdminRequired means the
 * Nextcloud SecurityMiddleware enforces the admin gate by default, and the
 * mutating endpoints additionally carry #[AuthorizedAdminSetting] for delegated-
 * admin auditability. Per hydra ADR-022 the controller holds no policy — it
 * delegates to WooService, which consumes the OpenRegister abstractions (deck leaf,
 * approval-workflow, workflow-integration, immutable audit trail).
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
 * @spec openspec/specs/woo-transparency/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\WooService;
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
 * Controller for WOO transparency workflow operations.
 */
class WooController extends Controller
{
    /**
     * Constructor.
     *
     * @param string       $appName     The app name.
     * @param IRequest     $request     The request.
     * @param WooService   $wooService  The WOO workflow service.
     * @param IL10N        $l10n        The localization service.
     * @param IUserSession $userSession The current user session.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly WooService $wooService,
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Return the WOO weigeringsgronden (refusal grounds) catalogue.
     *
     * Read-only; authenticated. The grounds are a static legal catalogue (WOO Art.
     * 5.1/5.2) carrying no per-user data, so an authenticated read is sufficient.
     *
     * @return JSONResponse The (optionally filtered) grounds.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-transparency/spec.md#requirement-weigeringsgronden-refusal-grounds
     */
    public function weigeringsgronden(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $search = $this->request->getParam('search', null);
        if ($search !== null) {
            $search = (string) $search;
        }

        $grounds = $this->wooService->getWeigeringsgronden(search: $search);
        return new JSONResponse(['results' => $grounds, 'total' => count($grounds)]);

    }//end weigeringsgronden()

    /**
     * Create a WOO disclosure batch and provision its Deck board + cards.
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default). Acts on the
     * consumed OpenRegister ObjectService + deck leaf; the admin gate is the guard.
     *
     * @return JSONResponse The created batch (incl. deck board reference).
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function createBatch(): JSONResponse
    {
        try {
            $caseReference = (string) $this->request->getParam('caseReference', '');
            $documents     = (array) $this->request->getParam('documents', []);
            $boardId       = $this->request->getParam('boardId', null);
            if ($caseReference === '') {
                return new JSONResponse(data: ['error' => $this->l10n->t('caseReference is required')], statusCode: Http::STATUS_BAD_REQUEST);
            }

            if ($boardId !== null) {
                $boardId = (int) $boardId;
            }

            $batch = $this->wooService->createBatch(
                caseReference: $caseReference,
                documents: $documents,
                boardId: $boardId
            );
            return new JSONResponse($batch, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try

    }//end createBatch()

    /**
     * Get a WOO batch with its derived per-status document summary (progress).
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default).
     *
     * @param string $batchId The batch object uuid.
     *
     * @return JSONResponse The batch with documentSummary.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function getBatch(string $batchId): JSONResponse
    {
        try {
            return new JSONResponse($this->wooService->getBatch($batchId));
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        }

    }//end getBatch()

    /**
     * Update a document assessment and move its linked Deck card via the leaf.
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default). Acts on an
     * arbitrary assessment id through the consumed ObjectService; the admin gate is
     * the guard. Weigeringsgronden are required for a "niet_openbaar" assessment
     * (enforced in the service).
     *
     * @param string $batchId The batch uuid (path scoping).
     * @param string $docId   The document-assessment object uuid.
     *
     * @return JSONResponse The updated assessment.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function updateAssessment(string $batchId, string $docId): JSONResponse
    {
        try {
            $assessment        = (string) $this->request->getParam('assessment', '');
            $weigeringsgronden = (array) $this->request->getParam('weigeringsgronden', []);
            $result            = $this->wooService->updateAssessment(
                assessmentId: $docId,
                assessment: $assessment,
                weigeringsgronden: $weigeringsgronden
            );
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

    }//end updateAssessment()

    /**
     * Mark a batch ready for review (only when all documents are assessed).
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default). This opens
     * the approval gate; the ready_for_review → published transition itself is
     * gated by the configured OpenRegister approval-workflow chain (ADR-022).
     *
     * @param string $batchId The batch uuid.
     *
     * @return JSONResponse The updated batch.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function markReadyForReview(string $batchId): JSONResponse
    {
        try {
            return new JSONResponse($this->wooService->markReadyForReview($batchId));
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

    }//end markReadyForReview()

    /**
     * Generate the inventarislijst for a batch and return it as a CSV download.
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default). CSV is
     * UTF-8 with BOM; HTML (archival PDF/A source) is available via the `format`
     * query parameter.
     *
     * @param string $batchId The batch uuid.
     *
     * @return DataDownloadResponse|JSONResponse The download, or an error.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function inventarislijst(string $batchId): DataDownloadResponse|JSONResponse
    {
        try {
            $rows   = $this->wooService->buildInventarislijst($batchId);
            $format = (string) $this->request->getParam('format', 'csv');
            if ($format === 'html') {
                $html = $this->wooService->renderInventarislijstHtml($batchId, $rows);
                return new DataDownloadResponse($html, 'inventarislijst.html', 'text/html');
            }

            $csv = $this->wooService->renderInventarislijstCsv($rows);
            return new DataDownloadResponse($csv, 'inventarislijst.csv', 'text/csv');
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

    }//end inventarislijst()

    /**
     * Publish a completed WOO batch to a public reading room.
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default). The batch
     * must be in "ready_for_review" (i.e. have passed the approval-workflow gate);
     * the service rejects otherwise. Only openbaar + deels_openbaar documents are
     * published; the response includes the public reading-room URL.
     *
     * @param string $batchId The batch uuid.
     *
     * @return JSONResponse The publication + the public reading-room URL.
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/woo-transparency/spec.md#requirement-reading-room-publication
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function publishBatch(string $batchId): JSONResponse
    {
        try {
            return new JSONResponse($this->wooService->publishBatch($batchId));
        } catch (\Throwable $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

    }//end publishBatch()
}//end class
