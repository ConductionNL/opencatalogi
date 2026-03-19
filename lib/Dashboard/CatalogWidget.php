<?php
/**
 * Dashboard widget for catalog overview.
 *
 * @category Dashboard
 * @package  OCA\OpenCatalogi\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;

use OCA\OpenCatalogi\AppInfo\Application;

/**
 * Widget showing catalog overview on the dashboard.
 */
class CatalogWidget implements IWidget
{
    /**
     * Constructor.
     *
     * @param IL10N $l10n Localization service.
     */
    public function __construct(
        private IL10N $l10n,
    ) {

    }//end __construct()

    /**
     * Get the widget identifier.
     *
     * @return string
     */
    public function getId(): string
    {
        return 'opencatalogi_catalogi_widget';

    }//end getId()

    /**
     * Get the widget title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Catalogi Overview');

    }//end getTitle()

    /**
     * Get the widget display order.
     *
     * @return int
     */
    public function getOrder(): int
    {
        return 10;

    }//end getOrder()

    /**
     * Get the widget icon CSS class.
     *
     * @return string
     */
    public function getIconClass(): string
    {
        return 'icon-catalogi-widget';

    }//end getIconClass()

    /**
     * Get the widget URL.
     *
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return null;

    }//end getUrl()

    /**
     * Load the widget scripts and styles.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function load(): void
    {
        Util::addScript(application: Application::APP_ID, file: Application::APP_ID.'-catalogiWidget');
        Util::addStyle(application: Application::APP_ID, file: 'dashboardWidgets');

    }//end load()
}//end class
