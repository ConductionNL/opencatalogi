<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\ScriptManifestLoader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScriptManifestLoader.
 *
 * Covers manifest resolution and every fallback branch (missing file,
 * invalid JSON, non-array JSON, unknown entry) via the injectable js directory.
 */
class ScriptManifestLoaderTest extends TestCase
{

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/octest-manifest-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $manifest = $this->tmpDir . '/opencatalogi-entrypoints.json';
        if (is_file($manifest)) {
            unlink($manifest);
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function writeManifest(string $contents): void
    {
        file_put_contents($this->tmpDir . '/opencatalogi-entrypoints.json', $contents);
    }

    public function testReturnsOrderedChunksWithoutJsSuffixForKnownEntry(): void
    {
        $this->writeManifest(json_encode([
            'main' => [
                'opencatalogi-vendor.js',
                'opencatalogi-shared.js',
                'opencatalogi-main.js',
            ],
        ]));

        $chunks = ScriptManifestLoader::resolveEntryScripts('opencatalogi', 'main', $this->tmpDir);

        $this->assertSame(
            ['opencatalogi-vendor', 'opencatalogi-shared', 'opencatalogi-main'],
            $chunks,
        );
    }

    public function testReturnsNullWhenManifestFileIsMissing(): void
    {
        $chunks = ScriptManifestLoader::resolveEntryScripts('opencatalogi', 'main', $this->tmpDir);

        $this->assertNull($chunks);
    }

    public function testReturnsNullWhenEntryIsNotInManifest(): void
    {
        $this->writeManifest(json_encode(['main' => ['opencatalogi-main.js']]));

        $chunks = ScriptManifestLoader::resolveEntryScripts('opencatalogi', 'adminSettings', $this->tmpDir);

        $this->assertNull($chunks);
    }

    public function testReturnsNullWhenManifestIsInvalidJson(): void
    {
        $this->writeManifest('{ not valid json');

        $chunks = ScriptManifestLoader::resolveEntryScripts('opencatalogi', 'main', $this->tmpDir);

        $this->assertNull($chunks);
    }

    public function testReturnsNullWhenManifestIsNotAnArray(): void
    {
        $this->writeManifest('123');

        $chunks = ScriptManifestLoader::resolveEntryScripts('opencatalogi', 'main', $this->tmpDir);

        $this->assertNull($chunks);
    }
}
