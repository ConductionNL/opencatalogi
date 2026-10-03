<?php

/**
 * Publication Linker Test
 *
 * The public link to a publication, as a resident gets it in a saved-search
 * notice, in their dossier and on a share link: the publication page of
 * portaliq's site, absolute with host and port, when portaliq is there; an
 * admin's template wins; without portaliq, opencatalogi's public API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/saved-search-link-to-the-site/specs/saved-searches/spec.md#requirement-a-notice-links-to-the-publication-page-of-the-site
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Portal\PublicationLinker
 */
class PublicationLinkerTest extends TestCase {

	/**
	 * A linker over a config holding `$values`, a site on a port, and portaliq installed or not.
	 *
	 * @param array<string, string> $values    The opencatalogi app config.
	 * @param bool                  $portaliq  Whether portaliq is installed.
	 *
	 * @return PublicationLinker
	 */
	private function linker(array $values, bool $portaliq): PublicationLinker {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $values[$key] ?? $default
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'http://localhost:8080'.$path);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'portaliq' && $portaliq);

		return new PublicationLinker(appConfig: $config, urlGenerator: $urls, appManager: $apps);
	}

	/**
	 * With portaliq there, the link opens the publication page of the site, never the raw API.
	 */
	public function testTheLinkOpensThePublicationPageOfTheSite(): void {
		$this->assertSame(
			'http://localhost:8080/index.php/apps/portaliq/site?route=/publicatie/pub%201',
			$this->linker(values: [], portaliq: true)->url(id: 'pub 1')
		);
	}

	/**
	 * An admin's template wins over the site.
	 */
	public function testAnAdminTemplateWins(): void {
		$this->assertSame(
			'https://open.gemeente.nl/publicaties/pub-1',
			$this->linker(values: ['publication_url_template' => 'https://open.gemeente.nl/publicaties/{id}'], portaliq: true)->url(id: 'pub-1')
		);
	}

	/**
	 * Without portaliq there is no site, so the link is opencatalogi's public API in the named catalogue.
	 */
	public function testWithoutPortaliqTheLinkIsThePublicApi(): void {
		$this->assertSame(
			'http://localhost:8080/index.php/apps/opencatalogi/api/woo/pub-1',
			$this->linker(values: ['publication_url_catalog' => 'woo'], portaliq: false)->url(id: 'pub-1')
		);
	}
}
