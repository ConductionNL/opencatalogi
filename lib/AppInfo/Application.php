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
use OCA\OpenCatalogi\Listener\ObjectCreatedEventListener;
use OCA\OpenCatalogi\Listener\ObjectUpdatedEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\IConfig;
use OCP\App\IAppManager;

/**
 * Main Application class for OpenCatalogi
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'opencatalogi';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}//end constructor

	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';
		
		// Register dashboard widgets
		$context->registerDashboardWidget(CatalogWidget::class);
		$context->registerDashboardWidget(UnpublishedPublicationsWidget::class);
		$context->registerDashboardWidget(UnpublishedAttachmentsWidget::class);
				
		// Register event listeners for OpenRegister events
		$context->registerEventListener(ObjectCreatedEvent::class, ObjectCreatedEventListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ObjectUpdatedEventListener::class);
	}//end register

	public function boot(IBootContext $context): void {
		$container = $context->getServerContainer();

		// Check if initialization is needed based on version
		try {
			$config = $container->get(IConfig::class);
			$currentAppVersion = $container->get(IAppManager::class)->getAppVersion(self::APP_ID);
			$lastInitializedVersion = $config->getAppValue(self::APP_ID, 'last_initialized_version', '');
			
			// Only initialize if we haven't initialized this version yet
			if ($lastInitializedVersion !== $currentAppVersion) {
				$settingsService = $container->get(\OCA\OpenCatalogi\Service\SettingsService::class);
				$settingsService->initialize();
				
				// Mark this version as initialized
				$config->setAppValue(self::APP_ID, 'last_initialized_version', $currentAppVersion);
			}
		} catch (\Exception $e) {
			// Log error but don't fail the boot process
		}

		// @TODO: This should only run if the app is enabled for the user
		// @TODO: Lets in
		//$appManager = $container->get(AppManager::class);
		//if($appManager->isEnabledForUser('opencatalogi')){
			// Get app config to check if initial sync has been done
			$config = $container->get(IConfig::class);
					$initialSyncDone = $config->getAppValue(self::APP_ID, 'initial_sync_done', 'false');
			
			// Only run if initial sync hasn't been done
			if ($initialSyncDone === 'false') {
				try {
                    // @todo needs fixing
					// Get DirectoryService and run sync
					//$directoryService = $container->get(\OCA\OpenCatalogi\Service\DirectoryService::class);
					//$directoryService->doCronSync();
	
					// Mark initial sync as done
					// $config->setAppValue(self::APP_ID, 'initial_sync_done', 'true');
				} catch (\Exception $e) {
					// Removed redundant logging
				}
			}			
		//}		
	}//end boot
}
