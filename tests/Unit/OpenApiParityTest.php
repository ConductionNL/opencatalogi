<?php
/**
 * OpenCatalogi OpenAPI/routes parity test.
 *
 * Guards against `openapi.json` rotting: every `@PublicPage`/`#[PublicPage]`
 * route registered in `appinfo/routes.php` (for controllers owned by this
 * app) must appear as a documented `(verb, path)` pair in `openapi.json`,
 * and vice versa, modulo the explicit reason-bearing allowlists below.
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
 *
 * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-public-routes-and-documented-paths-cannot-drift-api-doc-002
 */

declare(strict_types=1);

namespace Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Bidirectional parity test between `appinfo/routes.php` and `openapi.json`.
 *
 * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-public-routes-and-documented-paths-cannot-drift-api-doc-002
 */
class OpenApiParityTest extends TestCase
{
    /**
     * `appinfo/routes.php` entries whose `name` is a fully-qualified,
     * non-app-owned controller class (the OpenRegister AppHost engine
     * generics — dashboard SPA shell, per-user preferences, Prometheus
     * metrics, health check; ADR-040). These are not `OCA\OpenCatalogi\
     * Controller\*` classes, are not composer-loadable from this app's test
     * process (OpenRegister is a sibling app, not a dependency — see
     * `tests/bootstrap-unit.php`), and are documented as part of the
     * engine's own observability contract, not this app's bespoke public
     * API. Every routes.php entry whose `name` contains a namespace
     * separator MUST be listed here with a reason, or the test fails —
     * this keeps a future engine-route addition from silently vanishing
     * from parity coverage.
     *
     * @var array<string, string> Route `name` => reason.
     */
    private const ENGINE_OWNED_ROUTES = [
        'OCA\OpenCatalogi\AppHost\Controller\GenericDashboard#page'            => self::REASON_ENGINE_DASHBOARD,
        'OCA\OpenCatalogi\AppHost\Controller\GenericDashboard#catchAll'        => self::REASON_ENGINE_DASHBOARD_CATCHALL,
        'OCA\OpenCatalogi\AppHost\Controller\GenericPreferences#getPreference' => self::REASON_ENGINE_PREFERENCES,
        'OCA\OpenCatalogi\AppHost\Controller\GenericPreferences#setPreference' => self::REASON_ENGINE_PREFERENCES,
        'OCA\OpenCatalogi\AppHost\Controller\GenericMetrics#index'             => self::REASON_ENGINE_METRICS,
        'OCA\OpenCatalogi\AppHost\Controller\GenericHealth#index'              => self::REASON_ENGINE_HEALTH,
    ];

    /**
     * Reason: engine-owned dashboard SPA shell.
     *
     * @var string
     */
    private const REASON_ENGINE_DASHBOARD = 'Dashboard SPA shell served by OpenRegister\'s shared AppHost engine '
        .'(ADR-040); an HTML template response, not a JSON API endpoint, and not app-owned code.';

    /**
     * Reason: engine-owned SPA history-mode catch-all.
     *
     * @var string
     */
    private const REASON_ENGINE_DASHBOARD_CATCHALL = 'SPA history-mode catch-all delegating to the same '
        .'engine-owned page() action as GenericDashboard#page.';

    /**
     * Reason: engine-owned per-user preferences.
     *
     * @var string
     */
    private const REASON_ENGINE_PREFERENCES = 'Generic per-user preference storage (shared nextcloud-vue widgets) '
        .'served by the AppHost engine; not this app\'s own controller.';

    /**
     * Reason: engine-owned admin-only Prometheus metrics.
     *
     * @var string
     */
    private const REASON_ENGINE_METRICS = 'Admin-only Prometheus metrics endpoint served by the AppHost engine; '
        .'observability contract, not the anonymous public API surface.';

    /**
     * Reason: engine-owned anonymous health check.
     *
     * @var string
     */
    private const REASON_ENGINE_HEALTH = 'Anonymous health check served by OpenRegister\'s shared AppHost engine '
        .'(ADR-040); documented as part of that engine\'s own observability contract, not this app\'s bespoke '
        .'public API. Follow-up per the public-api-openapi-document proposal\'s "out of scope" section.';

    /**
     * Documented `openapi.json` `(METHOD path)` pairs that intentionally have
     * no directly-corresponding `@PublicPage` route entry — none at present;
     * kept for the next drift that needs a reasoned exception rather than a
     * silent one.
     *
     * @var array<string, string> `"METHOD path"` => reason.
     */
    private const DOC_ONLY_ALLOWLIST = [];

    /**
     * Load `appinfo/routes.php` and return its `routes` array.
     *
     * @return array<int, array<string, mixed>> The route entries.
     */
    private function loadRoutes(): array
    {
        $routes = include __DIR__.'/../../appinfo/routes.php';

        return $routes['routes'];

    }//end loadRoutes()

    /**
     * Load `openapi.json` as an associative array.
     *
     * @return array<string, mixed> The decoded document.
     */
    private function loadOpenApiDocument(): array
    {
        $raw = file_get_contents(__DIR__.'/../../openapi.json');
        $this->assertIsString($raw, 'openapi.json must be readable');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'openapi.json must parse as JSON');

        return $decoded;

    }//end loadOpenApiDocument()

    /**
     * Read the `<version>` from `appinfo/info.xml`.
     *
     * @return string The app version string.
     */
    private function loadInfoXmlVersion(): string
    {
        $xml = simplexml_load_file(__DIR__.'/../../appinfo/info.xml');
        $this->assertNotFalse($xml, 'appinfo/info.xml must parse as XML');

        return (string) $xml->version;

    }//end loadInfoXmlVersion()

    /**
     * Convert an NC route path template to its OpenAPI path-template
     * equivalent. NC's `{param}` route placeholders already use OpenAPI's
     * own template syntax, so this is currently an identity transform —
     * implemented as its own step (rather than comparing raw NC urls
     * directly) so a future NC syntax that diverges from `{param}` has a
     * single place to add the conversion, per design decision D1.
     *
     * @param string $ncUrl The NC route `url` value.
     *
     * @return string The OpenAPI path template.
     */
    private function ncPathToOpenApiPath(string $ncUrl): string
    {
        return $ncUrl;

    }//end ncPathToOpenApiPath()

    /**
     * Determine whether a controller method is public (carries `@PublicPage`
     * as a docblock tag, or `#[PublicPage]` as a PHP attribute).
     *
     * @param ReflectionMethod $method The method to inspect.
     *
     * @return boolean True when the method is publicly (anonymously) reachable.
     */
    private function isPublicPageMethod(ReflectionMethod $method): bool
    {
        $docComment = $method->getDocComment();
        // Match `@PublicPage` only as an actual docblock tag (start of a `*`-prefixed
        // line), not as a substring of prose — e.g. ListingsController::add()'s
        // docblock explains it "Dropped @PublicPage and @NoCSRFRequired" in a
        // sentence, which a naive str_contains() would misread as the tag itself.
        if ($docComment !== false && preg_match('/^\s*\*\s*@PublicPage\b/m', $docComment) === 1) {
            return true;
        }

        foreach ($method->getAttributes() as $attribute) {
            $name          = $attribute->getName();
            $lastBackslash = strrpos($name, '\\');
            $offset        = 0;
            if ($lastBackslash !== false) {
                $offset = ($lastBackslash + 1);
            }

            $shortName = substr($name, $offset);
            if ($shortName === 'PublicPage') {
                return true;
            }
        }

        return false;

    }//end isPublicPageMethod()

    /**
     * Build the set of `"METHOD path"` pairs that MUST be documented: every
     * `appinfo/routes.php` entry for an app-owned controller (`name` without
     * a namespace separator) whose target method carries `@PublicPage`.
     *
     * @return array<string, array{route: string, class: string, method: string}> Map of `"METHOD path"` => provenance.
     */
    private function collectExpectedPublicRoutes(): array
    {
        $expected = [];

        foreach ($this->loadRoutes() as $route) {
            $name = $route['name'];

            if (str_contains($name, '\\') === true) {
                $this->assertArrayHasKey(
                    key: $name,
                    array: self::ENGINE_OWNED_ROUTES,
                    message: 'Fully-qualified route "'.$name.'" is not app-owned and is missing a reason '.
                        'in OpenApiParityTest::ENGINE_OWNED_ROUTES — add one so it is either '.
                        'consciously excluded or (if it belongs to this app) reflected upon instead.'
                );
                continue;
            }

            [$shortName, $methodName] = explode('#', $name, 2);
            $className = 'OCA\\OpenCatalogi\\Controller\\'.ucfirst($shortName).'Controller';

            $this->assertTrue(
                class_exists($className) === true,
                'Controller class "'.$className.'" for route "'.$name.'" does not exist.'
            );

            $method = new ReflectionMethod($className, $methodName);

            if ($this->isPublicPageMethod($method) === false) {
                continue;
            }

            $verb = strtoupper((string) $route['verb']);
            $path = $this->ncPathToOpenApiPath((string) $route['url']);
            $key  = $verb.' '.$path;

            $expected[$key] = [
                'route'  => $name,
                'class'  => $className,
                'method' => $methodName,
            ];
        }//end foreach

        return $expected;

    }//end collectExpectedPublicRoutes()

    /**
     * Build the set of `"METHOD path"` pairs actually documented in
     * `openapi.json`.
     *
     * @return array<string, true> Map of `"METHOD path"` => true.
     */
    private function collectDocumentedRoutes(): array
    {
        $document   = $this->loadOpenApiDocument();
        $documented = [];

        foreach (($document['paths'] ?? []) as $path => $operations) {
            foreach (array_keys($operations) as $verb) {
                if (in_array($verb, ['get', 'post', 'put', 'patch', 'delete', 'options'], true) === false) {
                    continue;
                }

                $documented[strtoupper($verb).' '.$path] = true;
            }
        }

        return $documented;

    }//end collectDocumentedRoutes()

    /**
     * Every `@PublicPage` route must be documented, and every documented
     * path must correspond to a real `@PublicPage` route (modulo
     * `DOC_ONLY_ALLOWLIST`).
     *
     * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-public-routes-and-documented-paths-cannot-drift-api-doc-002
     *
     * @return void
     */
    public function testPublicRoutesAndDocumentedPathsMatchBidirectionally(): void
    {
        $expected   = $this->collectExpectedPublicRoutes();
        $documented = $this->collectDocumentedRoutes();

        $missingFromDoc = array_diff(array_keys($expected), array_keys($documented));
        $missingLines   = array_map(
            static fn(string $key): string => '  - '.$key.' (route "'.$expected[$key]['route'].'", '.
                $expected[$key]['class'].'::'.$expected[$key]['method'].'())',
            $missingFromDoc
        );
        $this->assertSame(
            expected: [],
            actual: array_values($missingFromDoc),
            message: "Public route(s) missing from openapi.json:\n".implode("\n", $missingLines)
        );

        $extraInDoc = array_diff(array_keys($documented), array_keys($expected));
        $extraInDoc = array_values(
            array_filter(
                $extraInDoc,
                static fn(string $key): bool => array_key_exists($key, self::DOC_ONLY_ALLOWLIST) === false
            )
        );
        $extraLines = array_map(static fn(string $key): string => '  - '.$key, $extraInDoc);
        $this->assertSame(
            expected: [],
            actual: $extraInDoc,
            message: "openapi.json documents path(s) with no corresponding @PublicPage route (and no allowlist reason):\n".
                implode("\n", $extraLines)
        );

    }//end testPublicRoutesAndDocumentedPathsMatchBidirectionally()

    /**
     * `openapi.json`'s `info.version` MUST equal `appinfo/info.xml`'s
     * `<version>` (the shipped file's baseline; serve-time substitution in
     * `ApiDocumentationController` covers installed-but-not-rebuilt
     * instances — design decision D2).
     *
     * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-accurate-openapi-31-document-describes-the-public-api-api-doc-001
     *
     * @return void
     */
    public function testDocumentVersionMatchesInfoXml(): void
    {
        $document = $this->loadOpenApiDocument();

        $this->assertSame($this->loadInfoXmlVersion(), $document['info']['version']);

    }//end testDocumentVersionMatchesInfoXml()

    /**
     * The document must carry OpenCatalogi's real metadata — no leftover
     * template content from another app.
     *
     * @spec openspec/changes/public-api-openapi-document/specs/api-documentation/spec.md#requirement-accurate-openapi-31-document-describes-the-public-api-api-doc-001
     *
     * @return void
     */
    public function testDocumentMetadataIsAccurateAndForeignStubIsGone(): void
    {
        $document = $this->loadOpenApiDocument();

        $this->assertSame('OpenCatalogi', $document['info']['title']);
        $this->assertSame('EUPL-1.2', $document['info']['license']['name']);

        $raw = file_get_contents(__DIR__.'/../../openapi.json');
        $this->assertStringNotContainsStringIgnoringCase('dsonextcloud', $raw);
        $this->assertStringNotContainsStringIgnoringCase('"agpl"', $raw);

        foreach (array_keys($document['paths']) as $path) {
            $this->assertStringNotContainsStringIgnoringCase('dsonextcloud', $path);
        }

    }//end testDocumentMetadataIsAccurateAndForeignStubIsGone()

    /**
     * `openapi.json` must parse as valid JSON with a non-empty `paths` map —
     * a cheap smoke test that fails loudly (rather than via a confusing
     * downstream diff) if the file is ever hand-edited into invalid JSON.
     *
     * @return void
     */
    public function testDocumentParsesAndHasPaths(): void
    {
        $document = $this->loadOpenApiDocument();

        $this->assertArrayHasKey('paths', $document);
        $this->assertNotEmpty($document['paths']);
        $this->assertSame('3.1.0', $document['openapi']);

    }//end testDocumentParsesAndHasPaths()
}//end class
