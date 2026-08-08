<?php
/**
 * Coherence assertion: the PHP floor this app advertises to the App Store is
 * the same floor its own dependency manifest enforces.
 *
 * `appinfo/info.xml` is what users and the App Store read; `composer.json` is
 * what actually decides whether the app can be installed. When they disagree,
 * the listing promises a configuration that cannot exist — the app advertised
 * `<php min-version="8.0"/>` while `composer.json` required `^8.3`, so
 * `composer install` aborted on every version the listing claimed to support.
 * Nothing caught it, because CI only ever ran 8.3 and 8.4.
 *
 * Same class of defect as openconnector#1173 (an App Store range the app's own
 * dependencies could not deliver).
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

namespace Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts info.xml, composer.json's `require.php` and composer.json's
 * `config.platform.php` all name the same PHP floor.
 */
class AppManifestPhpFloorTest extends TestCase
{

    /**
     * Repository root, derived from this file's own location.
     *
     * @return string Absolute path to the repository root.
     */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);

    }//end repoRoot()


    /**
     * The `min-version` attribute of info.xml's `<php>` dependency.
     *
     * @return string The declared floor, e.g. "8.3".
     */
    private function infoXmlPhpFloor(): string
    {
        $path = $this->repoRoot().'/appinfo/info.xml';
        $this->assertFileExists($path, 'appinfo/info.xml is missing');

        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, 'appinfo/info.xml is not parseable XML');

        $nodes = $xml->xpath('/info/dependencies/php');
        $this->assertNotEmpty($nodes, 'info.xml declares no <php> dependency at all');

        $floor = (string) $nodes[0]['min-version'];
        $this->assertNotSame('', $floor, '<php> carries no min-version attribute');

        return $floor;

    }//end infoXmlPhpFloor()


    /**
     * composer.json, decoded.
     *
     * @return array<string, mixed> The decoded manifest.
     */
    private function composerManifest(): array
    {
        $path = $this->repoRoot().'/composer.json';
        $this->assertFileExists($path, 'composer.json is missing');

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'composer.json is not valid JSON');

        return $decoded;

    }//end composerManifest()


    /**
     * Lowest concrete version a Composer caret/tilde/>= constraint admits.
     *
     * Deliberately narrow: it understands `^X.Y`, `~X.Y`, `>=X.Y` and a bare
     * `X.Y`, which is the whole vocabulary this repository uses. Anything else
     * fails the test rather than being silently accepted — a parser that
     * shrugs at an unknown constraint would report "coherent" for a manifest
     * it never actually read.
     *
     * @param string $constraint The raw Composer constraint.
     *
     * @return string The lowest admitted version.
     */
    private function lowestAdmitted(string $constraint): string
    {
        $trimmed = trim($constraint);
        $matches = [];
        $matched = preg_match('/^(?:\^|~|>=)?\s*(\d+\.\d+(?:\.\d+)?)$/', $trimmed, $matches);

        $this->assertSame(
            1,
            $matched,
            sprintf(
                'composer.json php constraint "%s" is not one of the forms this '
                .'test understands (^X.Y, ~X.Y, >=X.Y, X.Y). Widen the test '
                .'deliberately rather than letting it pass on a constraint it '
                .'cannot read.',
                $trimmed
            )
        );

        return $matches[1];

    }//end lowestAdmitted()


    /**
     * The App Store listing must not advertise a PHP version on which the
     * app's own dependencies refuse to install.
     *
     * @return void
     */
    public function testInfoXmlFloorIsNotBelowComposerRequirement(): void
    {
        $infoFloor     = $this->infoXmlPhpFloor();
        $composerFloor = $this->lowestAdmitted((string) $this->composerManifest()['require']['php']);

        $this->assertTrue(
            version_compare($infoFloor, $composerFloor, '>='),
            sprintf(
                'appinfo/info.xml advertises PHP >= %s but composer.json requires '
                .'php %s, so `composer install` aborts on every version between '
                .'them. The listing promises a configuration that cannot exist.',
                $infoFloor,
                $composerFloor
            )
        );

    }//end testInfoXmlFloorIsNotBelowComposerRequirement()


    /**
     * The pinned Composer platform must itself satisfy the declared
     * requirement — otherwise the lock file was resolved for a PHP the app
     * says it does not support.
     *
     * @return void
     */
    public function testPinnedPlatformSatisfiesComposerRequirement(): void
    {
        $manifest = $this->composerManifest();
        $platform = (string) ($manifest['config']['platform']['php'] ?? '');

        $this->assertNotSame(
            '',
            $platform,
            'composer.json pins no config.platform.php, so the lock file was '
            .'resolved against whatever PHP happened to run — not a declared target.'
        );

        $required = $this->lowestAdmitted((string) $manifest['require']['php']);

        $this->assertTrue(
            version_compare($platform, $required, '>='),
            sprintf(
                'composer.json pins config.platform.php = %s but requires php >= %s.',
                $platform,
                $required
            )
        );

    }//end testPinnedPlatformSatisfiesComposerRequirement()


    /**
     * Nextcloud 32 — the floor this app declares — refuses to boot below PHP
     * 8.1 (`lib/versioncheck.php`: `if (PHP_VERSION_ID < 80100)`). Advertising
     * anything lower describes a server that would never start.
     *
     * @return void
     */
    public function testInfoXmlFloorIsReachableOnTheDeclaredNextcloudFloor(): void
    {
        $xml   = simplexml_load_file($this->repoRoot().'/appinfo/info.xml');
        $nodes = $xml->xpath('/info/dependencies/nextcloud');
        $this->assertNotEmpty($nodes, 'info.xml declares no <nextcloud> dependency');

        $ncFloor = (int) $nodes[0]['min-version'];
        $this->assertGreaterThanOrEqual(
            32,
            $ncFloor,
            'This assertion is written against a Nextcloud floor of 32 or later; '
            .'lowering the floor invalidates the PHP 8.1 figure below and the '
            .'test must be revisited, not relaxed.'
        );

        $this->assertTrue(
            version_compare($this->infoXmlPhpFloor(), '8.1', '>='),
            sprintf(
                'info.xml advertises PHP >= %s, but the declared Nextcloud floor '
                .'(%d) will not boot below PHP 8.1.',
                $this->infoXmlPhpFloor(),
                $ncFloor
            )
        );

    }//end testInfoXmlFloorIsReachableOnTheDeclaredNextcloudFloor()


}//end class
