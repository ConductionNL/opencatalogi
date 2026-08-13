<?php
/**
 * Loader that resolves webpack entrypoint chunks from the build manifest.
 *
 * Reads the `opencatalogi-entrypoints.json` manifest emitted by webpack and
 * registers every JS chunk of an entrypoint (shared vendor chunk first, then
 * the entry chunk) through Nextcloud's Util::addScript, falling back to a
 * single script name when the manifest is unavailable.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCP\Util;

/**
 * Resolves and registers webpack entrypoint chunks from the build manifest.
 */
class ScriptManifestLoader
{

    /**
     * In-memory cache of decoded manifests, keyed by absolute file path.
     *
     * @var array<string, array<string, string[]>> $manifestCache Decoded manifests per path.
     */
    private static array $manifestCache = [];

    /**
     * Register all JS chunks for an entrypoint, or a fallback script.
     *
     * @param string $appId          The application identifier.
     * @param string $entry          The webpack entrypoint name (e.g. 'main').
     * @param string $fallbackScript Script name to load when the manifest is missing.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
     */
    public static function addEntryScripts(
        string $appId,
        string $entry,
        string $fallbackScript
    ): void {
        $chunks = self::resolveEntryScripts(appId: $appId, entry: $entry);

        if ($chunks === null) {
            Util::addScript(application: $appId, file: $fallbackScript);
            return;
        }

        foreach ($chunks as $chunk) {
            Util::addScript(application: $appId, file: $chunk);
        }

    }//end addEntryScripts()

    /**
     * Resolve the ordered list of script names for an entrypoint.
     *
     * @param string      $appId       The application identifier.
     * @param string      $entry       The webpack entrypoint name.
     * @param string|null $jsDirectory Optional override of the js directory (used in tests).
     *
     * @return string[]|null Ordered script names without the .js suffix, or null when unavailable.
     */
    public static function resolveEntryScripts(
        string $appId,
        string $entry,
        ?string $jsDirectory=null
    ): ?array {
        $manifest = self::loadManifest(appId: $appId, jsDirectory: $jsDirectory);

        if ($manifest === null || isset($manifest[$entry]) === false) {
            return null;
        }

        $chunks = [];
        foreach ($manifest[$entry] as $file) {
            $chunks[] = preg_replace('/\.js$/', '', $file);
        }

        return $chunks;

    }//end resolveEntryScripts()

    /**
     * Load and decode the entrypoints manifest for an application.
     *
     * @param string      $appId       The application identifier.
     * @param string|null $jsDirectory Optional override of the js directory (used in tests).
     *
     * @return array<string, string[]>|null The decoded manifest, or null when unavailable.
     */
    private static function loadManifest(
        string $appId,
        ?string $jsDirectory=null
    ): ?array {
        $directory = ($jsDirectory ?? __DIR__.'/../../js');
        $path      = $directory.'/'.$appId.'-entrypoints.json';

        if (array_key_exists($path, self::$manifestCache) === true) {
            return self::$manifestCache[$path];
        }

        if (is_file($path) === false) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (is_array($decoded) === false) {
            return null;
        }

        self::$manifestCache[$path] = $decoded;
        return $decoded;

    }//end loadManifest()
}//end class
