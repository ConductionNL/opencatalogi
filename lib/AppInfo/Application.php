<?php
/**
 * Main application bootstrap class for OpenCatalogi.
 *
 * @category AppInfo
 * @package  OCA\OpenCatalogi\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

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
 * Main Application class for OpenCatalogi.
 */
class Application extends App implements IBootstrap
{

    public const APP_ID = 'opencatalogi';


    /**
     * Application constructor.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);

    }//end __construct()


    /**
     * Register services, widgets, and event listeners.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register dashboard widgets.
        $context->registerDashboardWidget(CatalogWidget::class);
        $context->registerDashboardWidget(UnpublishedPublicationsWidget::class);
        $context->registerDashboardWidget(UnpublishedAttachmentsWidget::class);

        // Register event listeners for OpenRegister events.
        $context->registerEventListener(
            eventClass: ObjectCreatedEvent::class,
            listenerClass: ObjectCreatedEventListener::class
        );
        $context->registerEventListener(
            eventClass: ObjectUpdatedEvent::class,
            listenerClass: ObjectUpdatedEventListener::class
        );

    }//end register()


    /**
     * Boot the application and perform version-based initialization.
     *
     * @param IBootContext $context The boot context.
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        $container = $context->getServerContainer();

        // Check if initialization is needed based on version.
        try {
            $config                 = $container->get(IConfig::class);
            $currentAppVersion      = $container->get(IAppManager::class)->getAppVersion(self::APP_ID);
            $lastInitializedVersion = $config->getAppValue(
                app: self::APP_ID,
                key: 'last_initialized_version',
                default: ''
            );

            // Only initialize if we haven't initialized this version yet.
            if ($lastInitializedVersion !== $currentAppVersion) {
                $settingsService = $container->get(\OCA\OpenCatalogi\Service\SettingsService::class);
                $settingsService->initialize();

                // Mark this version as initialized.
                $config->setAppValue(
                    app: self::APP_ID,
                    key: 'last_initialized_version',
                    value: $currentAppVersion
                );
            }
        } catch (\Exception $e) {
            // Log error but don't fail the boot process.
        }//end try

        // Get app config to check if initial sync has been done.
        $config          = $container->get(IConfig::class);
        $initialSyncDone = $config->getAppValue(
            app: self::APP_ID,
            key: 'initial_sync_done',
            default: 'false'
        );

        // Only run if initial sync hasn't been done.
        if ($initialSyncDone === 'false') {
            try {
                // Mark initial sync as done.
                // $config->setAppValue(self::APP_ID, 'initial_sync_done', 'true').
            } catch (\Exception $e) {
                // Removed redundant logging.
            }
        }

    }//end boot()


}//end class
