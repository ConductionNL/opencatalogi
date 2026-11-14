<?php

/**
 * Unit tests for PublicationLinksListener: a publication created through
 * OpenRegister carries its public page and its officer page, and a value a
 * caller sends for either is replaced.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\PublicationLinksListener;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Publication\PublicationLinks;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Listener\PublicationLinksListener
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicationLinks
 * @uses   \OCA\OpenCatalogi\Service\Portal\PublicationLinker
 */
final class PublicationLinksListenerTest extends TestCase {

	/**
	 * The publication schema id.
	 *
	 * @var string
	 */
	private const SCHEMA = '12';

	/**
	 * A create through OpenRegister, with portaliq installed and catalogue `woo` listing the schema, returns both pages.
	 *
	 * @return void
	 */
	public function testACreateThroughOpenRegisterReturnsBothPages(): void {
		$event = new ObjectCreatingEvent($this->entity(data: ['title' => 'Besluit']));
		// OpenRegister's SaveObject gives the entity its uuid before MagicMapper dispatches this event.
		self::assertSame('pub-1', $event->getObject()->getUuid());

		$this->listener(config: [], portaliq: true, catalogue: 'woo')->handle($event);

		self::assertSame(
			[
				'publicUrl'  => 'https://gemeente.nl/index.php/apps/portaliq/site?route=/publicatie/pub-1',
				'officerUrl' => 'https://gemeente.nl/index.php/apps/opencatalogi/publications/woo/pub-1',
			],
			$event->getModifiedData()
		);
	}//end testACreateThroughOpenRegisterReturnsBothPages()

	/**
	 * Without a template, portaliq and a default catalogue, both pages are empty, never the object's API address.
	 *
	 * @return void
	 */
	public function testNoPublicLinkLeavesPublicUrlEmpty(): void {
		$event = new ObjectCreatingEvent($this->entity(data: []));

		$this->listener(config: ['publication_url_catalog' => ''], portaliq: false, catalogue: null)->handle($event);

		self::assertSame(['publicUrl' => '', 'officerUrl' => ''], $event->getModifiedData());
	}//end testNoPublicLinkLeavesPublicUrlEmpty()

	/**
	 * What a caller sends for either page is replaced; other modified data is kept.
	 *
	 * @return void
	 */
	public function testACallerSuppliedValueIsIgnored(): void {
		$event = new ObjectCreatingEvent($this->entity(data: ['publicUrl' => 'https://evil.example/x', 'officerUrl' => 'https://evil.example/y']));
		$event->setModifiedData(['publicUrl' => 'https://evil.example/x', 'completeness' => 'incomplete']);

		$this->listener(config: ['publication_url_catalog' => 'woo'], portaliq: false, catalogue: null)->handle($event);

		self::assertSame(
			[
				'publicUrl'    => 'https://gemeente.nl/index.php/apps/opencatalogi/api/woo/pub-1',
				'completeness' => 'incomplete',
				'officerUrl'   => 'https://gemeente.nl/index.php/apps/opencatalogi/publications/woo',
			],
			$event->getModifiedData()
		);
	}//end testACallerSuppliedValueIsIgnored()

	/**
	 * An update cannot set either page: both are computed again over what it sent.
	 *
	 * @return void
	 */
	public function testAnUpdateRecomputesBothPages(): void {
		$stored = $this->entity(data: ['publicUrl' => 'https://old.example/pub-1']);
		$update = new ObjectUpdatingEvent($this->entity(data: ['publicUrl' => 'https://evil.example/x']), $stored);
		$update->setModifiedData(['officerUrl' => 'https://evil.example/y']);

		$this->listener(config: [], portaliq: true, catalogue: 'woo')->handle($update);

		self::assertSame(
			[
				'officerUrl' => 'https://gemeente.nl/index.php/apps/opencatalogi/publications/woo/pub-1',
				'publicUrl'  => 'https://gemeente.nl/index.php/apps/portaliq/site?route=/publicatie/pub-1',
			],
			$update->getModifiedData()
		);
	}//end testAnUpdateRecomputesBothPages()

	/**
	 * Another schema, an unconfigured publication schema and another event are left alone.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreLeftAlone(): void {
		$other = new ObjectCreatingEvent($this->entity(data: [], schema: '99'));
		$this->listener(config: [], portaliq: true, catalogue: 'woo')->handle($other);
		self::assertSame([], $other->getModifiedData());

		$unconfigured = new ObjectCreatingEvent($this->entity(data: []));
		$this->listener(config: ['publication_schema' => ''], portaliq: true, catalogue: 'woo')->handle($unconfigured);
		self::assertSame([], $unconfigured->getModifiedData());

		$otherUpdate = new ObjectUpdatingEvent($this->entity(data: [], schema: '99'), $this->entity(data: [], schema: '99'));
		$this->listener(config: [], portaliq: true, catalogue: 'woo')->handle($otherUpdate);
		self::assertSame([], $otherUpdate->getModifiedData());

		$this->listener(config: [], portaliq: true, catalogue: 'woo')->handle(new \OCP\EventDispatcher\Event());
		self::addToAssertionCount(1);
	}//end testOtherObjectsAreLeftAlone()

	/**
	 * The listener over real link builders, a catalogue lookup and these app settings.
	 *
	 * @param array<string, string> $config    App settings, over `publication_schema` 12.
	 * @param bool                  $portaliq  Whether portaliq is installed.
	 * @param string|null           $catalogue The slug of the catalogue listing the schema, null for none.
	 *
	 * @return PublicationLinksListener The listener.
	 */
	private function listener(array $config, bool $portaliq, ?string $catalogue): PublicationLinksListener {
		$values = $config + ['publication_schema' => self::SCHEMA];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://gemeente.nl' . $path);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => match ($route) {
				'opencatalogi.ui.publicationsPage' => 'https://gemeente.nl/index.php/apps/opencatalogi/publications/' . $args['catalogSlug'] . '/' . $args['id'],
				'opencatalogi.ui.publicationsIndex' => 'https://gemeente.nl/index.php/apps/opencatalogi/publications/' . $args['catalogSlug'],
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'portaliq' && $portaliq);

		$catalogs = $this->createMock(CatalogiService::class);
		$catalogs->method('getCatalogSlugForSchema')->with(self::SCHEMA)->willReturn($catalogue);

		return new PublicationLinksListener(
			links: new PublicationLinks(
				linker: new PublicationLinker(appConfig: $appConfig, urlGenerator: $urls, appManager: $apps),
				catalogs: $catalogs,
				urls: $urls,
				config: $appConfig
			),
			config: $appConfig
		);
	}//end listener()

	/**
	 * An object of a schema with these properties and uuid `pub-1`.
	 *
	 * @param array<string, mixed> $data   The properties.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data, string $schema = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setUuid('pub-1');
		$entity->setObject($data);

		return $entity;
	}//end entity()
}//end class
