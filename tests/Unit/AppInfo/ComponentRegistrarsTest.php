<?php

/**
 * Pins what each component registrar registers.
 *
 * Application::register() delegates to one registrar per component. These
 * tests hold the exact registrations, so a listener, widget or binding that
 * goes missing in a registrar fails here instead of silently at runtime.
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

namespace Unit\AppInfo;

use OCA\OpenCatalogi\Dashboard\CatalogWidget;
use OCA\OpenCatalogi\Dashboard\DashboardRegistrar;
use OCA\OpenCatalogi\Dashboard\MostViewedPublicationsWidget;
use OCA\OpenCatalogi\Dashboard\RetentionWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedAttachmentsWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedPublicationsWidget;
use OCA\OpenCatalogi\Listener\CatalogAccessListener;
use OCA\OpenCatalogi\Listener\CatalogCacheEventListener;
use OCA\OpenCatalogi\Listener\CatalogSchemaEventListener;
use OCA\OpenCatalogi\Listener\CatalogScopePendingListener;
use OCA\OpenCatalogi\Listener\ListenerRegistrar;
use OCA\OpenCatalogi\Listener\ObjectCreatedEventListener;
use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenCatalogi\Listener\PlooiDeliveryListener;
use OCA\OpenCatalogi\Listener\PortalAccountRemovedListener;
use OCA\OpenCatalogi\Listener\ProvideManifestConfigStateListener;
use OCA\OpenCatalogi\Listener\ToolRegistrationListener;
use OCA\OpenCatalogi\Listener\WooReadinessTriggerListener;
use OCA\OpenCatalogi\Mcp\McpRegistrar;
use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
use OCA\OpenCatalogi\Observability\ObservabilityRegistrar;
use OCA\OpenCatalogi\Observability\OpenCatalogiMetricsProvider;
use OCA\OpenCatalogi\Service\Catalogue\GatewayCaseTypeSourceReader;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\ServiceRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the per-component registrars.
 */
class ComponentRegistrarsTest extends TestCase {

	private const OR_EVENT = 'OCA\\OpenRegister\\Event\\';

	/**
	 * Dashboard widgets, in registration order.
	 *
	 * @return void
	 */
	public function testDashboardRegistrarRegistersEveryWidget(): void {
		$widgets = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerDashboardWidget')->willReturnCallback(
			static function (string $widget) use (&$widgets): void {
				$widgets[] = $widget;
			}
		);

		(new DashboardRegistrar())->register($context);

		$this->assertSame(
			[
				CatalogWidget::class,
				UnpublishedPublicationsWidget::class,
				UnpublishedAttachmentsWidget::class,
				MostViewedPublicationsWidget::class,
				RetentionWidget::class,
			],
			$widgets
		);

	}//end testDashboardRegistrarRegistersEveryWidget()

	/**
	 * Every event listener, in registration order.
	 *
	 * @return void
	 */
	public function testListenerRegistrarSubscribesEveryListener(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = [$event, $listener];
			}
		);

		(new ListenerRegistrar())->register($context);

		$e = self::OR_EVENT;
		$this->assertSame(
			[
				[$e . 'ObjectCreatedEvent', ObjectCreatedEventListener::class],
				[$e . 'ObjectUpdatedEvent', ObjectUpdatedEventListener::class],
				[$e . 'ObjectUpdatedEvent', PortalAccountRemovedListener::class],
				[$e . 'ObjectUpdatedEvent', PlooiDeliveryListener::class],
				[$e . 'ObjectCreatedEvent', WooReadinessTriggerListener::class],
				[$e . 'ObjectUpdatedEvent', WooReadinessTriggerListener::class],
				[$e . 'ObjectCreatedEvent', CatalogCacheEventListener::class],
				[$e . 'ObjectUpdatedEvent', CatalogCacheEventListener::class],
				[$e . 'ObjectDeletedEvent', CatalogCacheEventListener::class],
				[$e . 'ObjectCreatingEvent', CatalogSchemaEventListener::class],
				[$e . 'ObjectUpdatingEvent', CatalogSchemaEventListener::class],
				[$e . 'ObjectCreatingEvent', CatalogAccessListener::class],
				[$e . 'ObjectUpdatingEvent', CatalogAccessListener::class],
				[$e . 'ObjectCreatedEvent', CatalogAccessListener::class],
				[$e . 'ObjectUpdatedEvent', CatalogAccessListener::class],
				[$e . 'ObjectDeletedEvent', CatalogAccessListener::class],
				[$e . 'RegisterCreatedEvent', CatalogScopePendingListener::class],
				[$e . 'RegisterUpdatedEvent', CatalogScopePendingListener::class],
				[$e . 'ToolRegistrationEvent', ToolRegistrationListener::class],
				['OCP\\AppFramework\\Http\\Events\\BeforeTemplateRenderedEvent', ProvideManifestConfigStateListener::class],
			],
			$pairs
		);

	}//end testListenerRegistrarSubscribesEveryListener()

	/**
	 * The MCP and metrics providers are bound under the keys OpenRegister enumerates.
	 *
	 * @return void
	 */
	public function testProviderRegistrarsBindTheEnumeratedKeys(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new McpRegistrar())->register($context);
		(new ObservabilityRegistrar())->register($context);

		$this->assertSame(
			[
				'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::opencatalogi' => OpenCatalogiToolProvider::class,
				'OCA\\OpenRegister\\AppHost\\IMetricsProvider::opencatalogi' => OpenCatalogiMetricsProvider::class,
			],
			$aliases
		);

	}//end testProviderRegistrarsBindTheEnumeratedKeys()

	/**
	 * The service registrar binds the case type reader and builds the collections service with the app id.
	 *
	 * @return void
	 */
	public function testServiceRegistrarBindsReaderAndScopesCollectionsToTheApp(): void {
		$aliases   = [];
		$factories = [];
		$context   = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ServiceRegistrar(appName: 'catalogue-app'))->register($context);

		$this->assertSame(
			['OCA\\OpenCatalogi\\Service\\Catalogue\\CaseTypeSourceReader' => GatewayCaseTypeSourceReader::class],
			$aliases
		);
		$this->assertSame([PublishedCollectionsService::class], array_keys($factories));

		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('getValueString')
			->with('catalogue-app', $this->anything(), $this->anything())
			->willReturn('');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[IAppConfig::class, $config],
				[PublicationRuleService::class, $this->createMock(PublicationRuleService::class)],
			]
		);

		$service = $factories[PublishedCollectionsService::class]($container);
		$this->assertInstanceOf(PublishedCollectionsService::class, $service);
		$service->collections();

	}//end testServiceRegistrarBindsReaderAndScopesCollectionsToTheApp()
}//end class
