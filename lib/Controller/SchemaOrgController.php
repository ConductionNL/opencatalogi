<?php
/**
 * OpenCatalogi schema.org discoverability controller.
 *
 * Public, CORS-enabled, content-negotiated schema.org JSON-LD endpoint for a
 * catalog (`DataCatalog`). A thin rendering layer (hydra ADR-022): selection and
 * mapping live in {@see SchemaOrgService}, which renders from the `x-schema-org`
 * markers on the OpenRegister schemas. The per-publication schema.org
 * representation is content-negotiated on the existing publications#show endpoint;
 * this controller serves the catalog-level `DataCatalog` node at its canonical
 * public URL, mirroring the DCAT catalog endpoint.
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

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\SchemaOrgService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the public schema.org catalog surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/structured-data-discoverability/spec.md
 */
class SchemaOrgController extends Controller
{
    /**
     * SchemaOrgController constructor.
     *
     * @param string           $appName          The app name.
     * @param IRequest         $request          The request object.
     * @param SchemaOrgService $schemaOrgService The schema.org JSON-LD renderer.
     * @param CatalogiService  $catalogiService  Catalog resolution (slug → catalog).
     * @param IL10N            $l10n             Localization service.
     * @param LoggerInterface  $logger           PSR-3 logger.
     * @param IAppConfig|null  $appConfig        App config for the CORS allowlist.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SchemaOrgService $schemaOrgService,
        private readonly CatalogiService $catalogiService,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
        private readonly ?IAppConfig $appConfig=null,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Resolve the Access-Control-Allow-Origin header value.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV);
     * '*' (default) emits a literal '*'; an unvetted caller Origin is never echoed back
     * unless explicitly allowlisted (mirrors DcatController / PublicationsController #735).
     *
     * @return string The Access-Control-Allow-Origin value.
     *
     * @spec exclude CORS-policy plumbing mirroring DcatController; fail-closed, no domain behaviour.
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
     * Build the standard public-CORS header set.
     *
     * @return array<string, string> The header map.
     *
     * @spec exclude CORS-header plumbing.
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
     * Preflighted CORS response for the public schema.org OPTIONS route.
     *
     * @return Response The CORS preflight response.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/specs/structured-data-discoverability/spec.md
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
     * Serve the schema.org `DataCatalog` representation of a catalog.
     *
     * A single well-formed JSON-LD document (no result envelope) suitable for
     * direct `<script type="application/ld+json">` embedding by the external
     * WOO/open-data frontend. Only publicly visible publications are listed.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return JSONResponse The schema.org DataCatalog node (404 when unknown).
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/specs/structured-data-discoverability/spec.md
     */
    public function catalog(string $catalogSlug): JSONResponse
    {
        try {
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
            if ($catalog === null) {
                return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
            }

            $node     = $this->schemaOrgService->buildCatalogNode($catalog, $catalogSlug);
            $response = new JSONResponse($node, 200);
            $response->addHeader('Content-Type', 'application/ld+json');
            foreach ($this->corsHeaders() as $name => $value) {
                $response->addHeader($name, $value);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error(
                '[SchemaOrgController::catalog] Failed to build schema.org catalog document',
                ['catalogSlug' => $catalogSlug, 'error' => $e->getMessage()]
            );
            return new JSONResponse(['error' => $this->l10n->t('Internal server error')], 500);
        }//end try

    }//end catalog()
}//end class
