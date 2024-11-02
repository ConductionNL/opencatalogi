<?php

declare(strict_types=1);

namespace OCA\OpenCatalogi\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\OpenCatalogi\Dashboard\CatalogWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedPublicationsWidget;
use OCA\OpenCatalogi\Dashboard\UnpublishedAttachmentsWidget;
use OCP\IConfig;

/**
 * Main Application class for OpenCatalogi
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'opencatalogi';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);

		// Listen for the post installation event
		$container = $this->getContainer();
		$this->listen($container);
	}

	/**
	 * Register event listeners
	 */
	private function listen($container): void {
		$dispatcher = $container->getServer()->getEventDispatcher();
		
		// Listen for app installation
		$dispatcher->addListener('app.register', function() use ($container) {
			// Get app config to check if initial sync has been done
			$config = $container->get(IConfig::class);
			$initialSyncDone = $config->getAppValue(self::APP_ID, 'initial_sync_done', 'false');

			// Only run if initial sync hasn't been done
			if ($initialSyncDone === 'false') {
				try {
					// Get DirectoryService and run sync
					$directoryService = $container->get(\OCA\OpenCatalogi\Service\DirectoryService::class);
					$directoryService->doCronSync();

					// Mark initial sync as done
					$config->setAppValue(self::APP_ID, 'initial_sync_done', 'true');
				} catch (\Exception $e) {
					\OC::$server->getLogger()->error('Failed to run initial directory sync: ' . $e->getMessage(), [
						'app' => self::APP_ID
					]);
				}
			}
		});
	}

	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';
		$context->registerDashboardWidget(CatalogWidget::class);
		$context->registerDashboardWidget(UnpublishedPublicationsWidget::class);
		$context->registerDashboardWidget(UnpublishedAttachmentsWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
