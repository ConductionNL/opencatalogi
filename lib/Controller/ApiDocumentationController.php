<?php
/**
 * OpenCatalogi API documentation controller.
 *
 * Serves the static `openapi.json` OpenAPI 3.1 document describing this
 * app's public (`@PublicPage`) API surface, with `info.version` substituted
 * at serve time from the installed app version so an installed-but-not-
 * rebuilt instance never reports a stale version (design decision D2).
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-the-document-is-served-publicly-with-cors-api-doc-003
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the public OpenAPI self-documentation endpoint.
 *
 * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-the-document-is-served-publicly-with-cors-api-doc-003
 */
class ApiDocumentationController extends Controller
{
    /**
     * ApiDocumentationController constructor.
     *
     * @param string          $appName    The app name.
     * @param IRequest        $request    The request object.
     * @param IAppManager     $appManager App manager, to resolve the installed app version.
     * @param IL10N           $l10n       Localization service.
     * @param LoggerInterface $logger     PSR-3 logger.
     * @param IAppConfig|null $appConfig  App config for the CORS allowlist.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppManager $appManager,
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
     * unless explicitly allowlisted (mirrors DcatController / SchemaOrgController #735).
     *
     * @return string The Access-Control-Allow-Origin value.
     *
     * @spec exclude CORS-policy plumbing mirroring DcatController/SchemaOrgController; fail-closed, no domain behaviour.
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
     * Serve the OpenAPI 3.1 document describing the public API surface.
     *
     * Reads the shipped `openapi.json` from the app root and overwrites
     * `info.version` with the installed app version (design decision D2) so
     * the served document is accurate even when the shipped file's version
     * has not yet been bumped by a redeploy. The parity test
     * (`tests/Unit/OpenApiParityTest.php`) is what keeps the shipped file's
     * `info.version` in sync with `appinfo/info.xml` in the first place.
     *
     * @return JSONResponse The OpenAPI document, or a 500 on read/parse failure.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-the-document-is-served-publicly-with-cors-api-doc-003
     */
    public function index(): JSONResponse
    {
        try {
            $raw = file_get_contents(__DIR__.'/../../openapi.json');
            if ($raw === false) {
                throw new \RuntimeException('openapi.json could not be read.');
            }

            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $document['info']['version'] = $this->appManager->getAppVersion($this->appName);

            $response = new JSONResponse($document, Http::STATUS_OK);
            foreach ($this->corsHeaders() as $name => $value) {
                $response->addHeader($name, $value);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('[ApiDocumentationController::index] Failed to serve openapi.json', ['error' => $e->getMessage()]);

            $response = new JSONResponse(
                ['error' => $this->l10n->t('Internal server error')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
            foreach ($this->corsHeaders() as $name => $value) {
                $response->addHeader($name, $value);
            }

            return $response;
        }//end try

    }//end index()
}//end class
