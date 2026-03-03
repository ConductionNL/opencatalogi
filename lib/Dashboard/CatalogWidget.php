<?php
/**
 * Catalog Dashboard Widget for OpenCatalogi.
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
use OCP\IURLGenerator;
use OCP\Util;

use OCA\OpenCatalogi\AppInfo\Application;

class CatalogWidget implements IWidget
{


    /**
     * Constructor for CatalogWidget.
     *
     * @param IL10N         $l10n Localization service.
     * @param IURLGenerator $url  URL generator service.
     */
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $url
    ) {

    }//end __construct()


    /**
     * Get the widget identifier.
     *
     * @inheritDoc
     *
     * @return string The widget ID.
     */
    public function getId(): string
    {
        return 'opencatalogi_catalogi_widget';

    }//end getId()


    /**
     * Get the widget title.
     *
     * @inheritDoc
     *
     * @return string The translated widget title.
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Catalogi Overview');

    }//end getTitle()


    /**
     * Get the widget display order.
     *
     * @inheritDoc
     *
     * @return integer The display order.
     */
    public function getOrder(): int
    {
        return 10;

    }//end getOrder()


    /**
     * Get the widget icon CSS class.
     *
     * @inheritDoc
     *
     * @return string The icon CSS class.
     */
    public function getIconClass(): string
    {
        return 'icon-catalogi-widget';

    }//end getIconClass()


    /**
     * Get the widget URL.
     *
     * @inheritDoc
     *
     * @return string|null The widget URL or null.
     */
    public function getUrl(): ?string
    {
        return null;

    }//end getUrl()


    /**
     * Load widget assets.
     *
     * @inheritDoc
     *
     * @return void
     */
    public function load(): void
    {
        Util::addScript(Application::APP_ID, Application::APP_ID.'-catalogiWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');

    }//end load()


}//end class
