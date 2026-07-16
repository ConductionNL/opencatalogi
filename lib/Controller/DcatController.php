<?php
/**
 * OpenCatalogi DCAT-AP-NL harvest controller.
 *
 * Public, CORS-enabled, content-negotiated DCAT-AP-NL endpoints over the existing
 * published-object set. A thin rendering layer (hydra ADR-022): all selection,
 * mapping, serialization, pagination and caching live in DcatService /
 * DcatMappingService / DcatSerializer. Plus an admin-gated feed-validation action.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Http\DcatResponse;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the public DCAT-AP-NL harvest surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/dcat-ap-harvest/tasks.md#task-3-serializer-public-endpoints
 */
class DcatController extends Controller
{
    /**
     * DcatController constructor.
     *
     * @param string          $appName         The app name.
     * @param IRequest        $request         The request object.
     * @param DcatService     $dcatService     The DCAT orchestration service.
     * @param DcatSerializer  $serializer      The DCAT serializer.
     * @param CatalogiService $catalogiService Catalog resolution (slug → catalog).
     * @param IL10N           $l10n            Localization service.
     * @param LoggerInterface $logger          PSR-3 logger.
     * @param IAppConfig|null $appConfig       App config for the CORS allowlist.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly DcatService $dcatService,
        private readonly DcatSerializer $serializer,
        private readonly CatalogiService $catalogiService,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
        private readonly ?IAppConfig $appConfig=null,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Resolve the Access-Control-Allow-Origin header value for the current request.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
     * '*' (the default) emits a literal '*'; an unvetted caller Origin is never echoed
     * back unless explicitly allowlisted — mirroring PublicationsController (#735).
     *
     * @return string The Access-Control-Allow-Origin value.
     *
     * @spec exclude CORS-policy plumbing mirroring PublicationsController; fail-closed on
     *       Origin reflection, no domain behaviour.
     */
    private function resolveAllowedOrigin(): string
    {
        $configured = '*';
        if ($this->appConfig !== null) {
            $configured = trim($this->appConfig->getValueString($this->appName, 'cors_allowed_origins', '*'));
        }

        if ($configured === '' || $configured === '*') {
            return '*';
        }

        $allowlist = array_values(
            array_filter(
                array_map('trim', explode(',', $configured)),
                static fn(string $entry): bool => $entry !== ''
            )
        );

        $callerOrigin = $this->request->getHeader('Origin');
        if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
            return $callerOrigin;
        }

        return ($allowlist[0] ?? '*');

    }//end resolveAllowedOrigin()

    /**
     * Build the standard public-CORS header set for DCAT responses.
     *
     * @return array<string, string> The header map.
     */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin'  => $this->resolveAllowedOrigin(),
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
        ];

    }//end corsHeaders()

    /**
     * Preflighted CORS response for the public DCAT OPTIONS routes.
     *
     * @return Response The CORS preflight response.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
     */
    public function preflightedCors(): Response
    {
        $response = new Response();
        foreach ($this->corsHeaders() as $name => $value) {
            $response->addHeader($name, $value);
        }

        $response->addHeader('Access-Control-Max-Age', '1728000');
        $response->addHeader('Access-Control-Allow-Credentials', 'false');
        return $response;

    }//end preflightedCors()

    /**
     * Serve the instance-level DCAT-AP-NL document listing every enabled catalog.
     *
     * @return Response The serialized DCAT document (or 406 on unsupported format).
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-instance-level-dcat-catalog-document-dcat-002
     */
    public function instance(): Response
    {
        try {
            $format = $this->serializer->negotiate(
                $this->request->getParam('format'),
                $this->request->getHeader('Accept')
            );
            if ($format === null) {
                return $this->unsupportedFormat();
            }

            $document = $this->dcatService->buildInstanceDocument();
            return $this->respond($document, $format);
        } catch (\Throwable $e) {
            $this->logger->error('[DcatController::instance] Failed to build instance DCAT document', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Internal server error')], 500);
        }

    }//end instance()

    /**
     * Serve the per-catalog DCAT-AP-NL document.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return Response The serialized DCAT document (404 when unknown/disabled,
     *                  406 on unsupported format, 304 on conditional GET).
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-per-catalog-dcat-ap-nl-document-endpoint-dcat-001
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-only-publicly-visible-objects-appear-in-the-feed-dcat-003
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-content-negotiation-across-rdf-serializations-dcat-007
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-harvester-grade-pagination-and-caching-dcat-008
     */
    public function catalog(string $catalogSlug): Response
    {
        try {
            $format = $this->serializer->negotiate(
                $this->request->getParam('format'),
                $this->request->getHeader('Accept')
            );
            if ($format === null) {
                return $this->unsupportedFormat();
            }

            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
            if ($catalog === null) {
                return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
            }

            if ($this->dcatService->isDcatEnabled($catalog) === false) {
                return new JSONResponse(['error' => $this->l10n->t('DCAT harvesting is not enabled for this catalog')], 404);
            }

            $page     = max(1, (int) $this->request->getParam('page', 1));
            $document = $this->dcatService->buildCatalogDocument(catalog: $catalog, catalogSlug: $catalogSlug, page: $page);

            // Conditional GET — return 304 when the harvester's ETag still matches.
            $ifNoneMatch = $this->request->getHeader('If-None-Match');
            $etag        = ($document['_meta']['etag'] ?? '');
            if ($ifNoneMatch !== '' && $etag !== '' && trim($ifNoneMatch) === $etag) {
                return new DcatResponse(
                    body: '',
                    contentType: DcatSerializer::FORMATS[$format],
                    status: 304,
                    headers: $this->cachingHeaders($document)
                );
            }

            return $this->respond($document, $format);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[DcatController::catalog] Failed to build catalog DCAT document',
                ['catalogSlug' => $catalogSlug, 'error' => $e->getMessage()]
            );
            return new JSONResponse(['error' => $this->l10n->t('Internal server error')], 500);
        }//end try

    }//end catalog()

    /**
     * Validate a catalog's DCAT feed against the DCAT-AP-NL mandatory-property checklist.
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces the admin
     * gate). Advisory only — never alters serving. Reports violations per dataset IRI.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return JSONResponse The list of violations (empty when the feed is compliant).
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-admin-configuration-and-feed-validation-dcat-010
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function validate(string $catalogSlug): JSONResponse
    {
        try {
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
            if ($catalog === null) {
                return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
            }

            $violations = $this->dcatService->validateCatalog($catalog, $catalogSlug);
            return new JSONResponse(
                [
                    'catalogSlug' => $catalogSlug,
                    'valid'       => empty($violations),
                    'violations'  => $violations,
                ],
                200
            );
        } catch (\Throwable $e) {
            $this->logger->error('[DcatController::validate] Validation failed', ['catalogSlug' => $catalogSlug, 'error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Internal server error')], 500);
        }

    }//end validate()

    /**
     * Validate a catalog's feed for data.overheid.nl (DONL) harvesting.
     *
     * Admin-only. Runs the DONL rule-set over the DCAT feed and returns the
     * canonical harvest-source URL plus per-dataset source/theme/mandatory
     * violations. Advisory — never alters serving (DCAT-NPF-001).
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return JSONResponse The DONL validation report (with the harvest-source URL).
     *
     * @NoCSRFRequired
     *
     * @spec openspec/specs/dcat-ap-harvest/spec.md
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function donlReport(string $catalogSlug): JSONResponse
    {
        try {
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
            if ($catalog === null) {
                return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
            }

            $report = $this->dcatService->validateForDonl($catalog, $catalogSlug);
            $report['catalogSlug'] = $catalogSlug;
            return new JSONResponse($report, 200);
        } catch (\Throwable $e) {
            $this->logger->error('[DcatController::donlReport] Validation failed', ['catalogSlug' => $catalogSlug, 'error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Internal server error')], 500);
        }

    }//end donlReport()

    /**
     * Serialize a document and wrap it in a CORS- and cache-headed DcatResponse.
     *
     * @param array<string, mixed> $document The JSON-LD document (with `_meta`).
     * @param string               $format   The negotiated format name.
     *
     * @return DcatResponse The serialized response.
     */
    private function respond(array $document, string $format): DcatResponse
    {
        $meta = ($document['_meta'] ?? []);
        unset($document['_meta']);

        $body    = $this->serializer->serialize($document, $format);
        $headers = array_merge($this->corsHeaders(), $this->cachingHeaders(['_meta' => $meta]));

        return new DcatResponse(
            body: $body,
            contentType: DcatSerializer::FORMATS[$format],
            status: 200,
            headers: $headers
        );

    }//end respond()

    /**
     * Build the Last-Modified/ETag caching headers from a document's `_meta`.
     *
     * @param array<string, mixed> $document The document carrying `_meta`.
     *
     * @return array<string, string> The caching headers.
     */
    private function cachingHeaders(array $document): array
    {
        $meta    = ($document['_meta'] ?? []);
        $headers = [];
        if (($meta['lastModified'] ?? '') !== '') {
            $headers['Last-Modified'] = $meta['lastModified'];
        }

        if (($meta['etag'] ?? '') !== '') {
            $headers['ETag'] = $meta['etag'];
        }

        return $headers;

    }//end cachingHeaders()

    /**
     * Build a 406 response listing the supported serializations.
     *
     * @return JSONResponse The 406 response.
     *
     * @spec openspec/specs/dcat-ap-harvest/spec.md#requirement-content-negotiation-across-rdf-serializations-dcat-007
     */
    private function unsupportedFormat(): JSONResponse
    {
        return new JSONResponse(
            [
                'error'     => $this->l10n->t('Unsupported serialization format'),
                'supported' => array_keys(DcatSerializer::FORMATS),
            ],
            406
        );

    }//end unsupportedFormat()
}//end class
