<?php
/**
 * OpenCatalogi manifest resolve-sentinel parity test.
 *
 * Anti-drift guard for `fix-woo-capability-provisioning` (design decision D4):
 * every `@resolve:<key>` sentinel appearing anywhere in the effective manifest
 * (base `src/manifest.json` + `src/manifest.d/*.json` fragments +
 * `src/menu-layout.json`, mirroring the merge `src/main.js` performs) must
 * have its `<key>` present in `ProvideManifestConfigStateListener::
 * MANIFEST_CONFIG_KEYS`. A sentinel whose key is absent from that list is
 * never substituted at runtime — it reaches the network as a literal string
 * and 404s (the exact defect this change fixes for the WOO keys). The list
 * is read via reflection, never duplicated here, so this test cannot itself
 * drift from the listener it guards.
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
 * @spec openspec/changes/fix-woo-capability-provisioning/specs/woo-transparency/spec.md#requirement-every-manifest-resolve-sentinel-is-backed-by-provided-initial-state-woo-prov-003
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\ProvideManifestConfigStateListener;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards `MANIFEST_CONFIG_KEYS` against silently drifting from the manifest's
 * actual `@resolve:<key>` sentinels.
 *
 * @spec openspec/changes/fix-woo-capability-provisioning/specs/woo-transparency/spec.md#requirement-every-manifest-resolve-sentinel-is-backed-by-provided-initial-state-woo-prov-003
 */
class ManifestSentinelParityTest extends TestCase
{
    /**
     * Regex matching a `@resolve:<key>` sentinel string, mirroring the
     * pattern `resolveManifestSentinelsSync()` in `src/main.js` uses.
     *
     * @var string
     */
    private const SENTINEL_PATTERN = '/@resolve:([a-z][a-z0-9_-]*)/';

    /**
     * Decode one manifest JSON file.
     *
     * @param string $path Absolute path to the JSON file.
     *
     * @return array<string, mixed> The decoded document.
     */
    private function loadJson(string $path): array
    {
        $raw = file_get_contents($path);
        $this->assertIsString($raw, $path.' must be readable');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, $path.' must parse as JSON');

        return $decoded;

    }//end loadJson()

    /**
     * Recursively collect every `@resolve:<key>` sentinel key found anywhere
     * (strings, nested arrays/objects) within a decoded manifest structure.
     *
     * @param mixed $node The (sub-)structure to scan.
     *
     * @return array<int, string> The sentinel keys found.
     */
    private function collectSentinelKeys(mixed $node): array
    {
        $found = [];

        if (is_array($node) === true) {
            foreach ($node as $value) {
                $found = array_merge($found, $this->collectSentinelKeys($value));
            }

            return $found;
        }

        if (is_string($node) === true && preg_match(self::SENTINEL_PATTERN, $node, $matches) === 1) {
            $found[] = $matches[1];
        }

        return $found;

    }//end collectSentinelKeys()

    /**
     * Build the effective manifest's set of `@resolve:<key>` sentinel keys:
     * base `src/manifest.json` + every `src/manifest.d/*.json` fragment +
     * `src/menu-layout.json` when present — the same file set `src/main.js`
     * merges before `resolveManifestSentinelsSync()` runs.
     *
     * @return array<int, string> The unique sentinel keys found.
     */
    private function collectEffectiveManifestSentinelKeys(): array
    {
        $srcDir = __DIR__.'/../../../src';
        $keys   = [];

        $keys = array_merge($keys, $this->collectSentinelKeys($this->loadJson($srcDir.'/manifest.json')));

        $fragmentDir = $srcDir.'/manifest.d';
        if (is_dir($fragmentDir) === true) {
            $fragments = glob($fragmentDir.'/*.json');
            $this->assertIsArray($fragments, 'manifest.d must be globbable');

            foreach ($fragments as $fragmentPath) {
                $keys = array_merge($keys, $this->collectSentinelKeys($this->loadJson($fragmentPath)));
            }
        }

        $menuLayoutPath = $srcDir.'/menu-layout.json';
        if (is_file($menuLayoutPath) === true) {
            $keys = array_merge($keys, $this->collectSentinelKeys($this->loadJson($menuLayoutPath)));
        }

        return array_values(array_unique($keys));

    }//end collectEffectiveManifestSentinelKeys()

    /**
     * Read `ProvideManifestConfigStateListener::MANIFEST_CONFIG_KEYS` via
     * reflection — never a duplicated literal list, so this test cannot drift
     * from the listener it guards (design decision D4).
     *
     * @return array<int, string> The declared manifest-config keys.
     */
    private function reflectManifestConfigKeys(): array
    {
        $reflection = new ReflectionClass(ProvideManifestConfigStateListener::class);
        $constant   = $reflection->getConstant('MANIFEST_CONFIG_KEYS');

        $this->assertIsArray($constant, 'MANIFEST_CONFIG_KEYS must be an array');

        return $constant;

    }//end reflectManifestConfigKeys()

    /**
     * Every `@resolve:<key>` sentinel in the effective manifest must have its
     * `<key>` present in `MANIFEST_CONFIG_KEYS`, or the frontend leaves the
     * literal sentinel string in place and it reaches the network as an
     * invalid register/schema id (404).
     *
     * @spec openspec/changes/fix-woo-capability-provisioning/specs/woo-transparency/spec.md#requirement-every-manifest-resolve-sentinel-is-backed-by-provided-initial-state-woo-prov-003
     *
     * @return void
     */
    public function testEveryManifestSentinelIsBackedByAManifestConfigKey(): void
    {
        $sentinelKeys = $this->collectEffectiveManifestSentinelKeys();
        $this->assertNotEmpty($sentinelKeys, 'Sanity check: the effective manifest should reference at least one @resolve: sentinel.');

        $configKeys = $this->reflectManifestConfigKeys();

        $unbacked = array_values(array_diff($sentinelKeys, $configKeys));

        $this->assertSame(
            expected: [],
            actual: $unbacked,
            message: 'Manifest @resolve: sentinel(s) with no backing key in '.
                'ProvideManifestConfigStateListener::MANIFEST_CONFIG_KEYS (they will reach the network as a '.
                'literal "@resolve:<key>" string and 404): '.implode(', ', $unbacked)
        );

    }//end testEveryManifestSentinelIsBackedByAManifestConfigKey()

    /**
     * The three WOO keys this change introduces must be members of
     * `MANIFEST_CONFIG_KEYS` (WOO-PROV-003).
     *
     * @spec openspec/changes/fix-woo-capability-provisioning/specs/woo-transparency/spec.md#requirement-every-manifest-resolve-sentinel-is-backed-by-provided-initial-state-woo-prov-003
     *
     * @return void
     */
    public function testWooKeysAreManifestConfigKeys(): void
    {
        $configKeys = $this->reflectManifestConfigKeys();

        $this->assertContains('woo_register', $configKeys);
        $this->assertContains('woo_batch_schema', $configKeys);
        $this->assertContains('woo_assessment_schema', $configKeys);

    }//end testWooKeysAreManifestConfigKeys()
}//end class
