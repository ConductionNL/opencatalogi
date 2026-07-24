<?php
/**
 * Dashboard widget for the retention review queue.
 *
 * @category Dashboard
 * @package  OCA\OpenCatalogi\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
 */

namespace OCA\OpenCatalogi\Dashboard;

use OCA\OpenCatalogi\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;

/**
 * Widget showing retention review-queue counts on the dashboard.
 */
class RetentionWidget implements IWidget
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
     * @return string The widget id.
     */
    public function getId(): string
    {
        return 'opencatalogi_retention_widget';

    }//end getId()

    /**
     * Get the widget title.
     *
     * @return string The widget title.
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Retention review');

    }//end getTitle()

    /**
     * Get the widget display order.
     *
     * @return int The order.
     */
    public function getOrder(): int
    {
        return 11;

    }//end getOrder()

    /**
     * Get the widget icon CSS class.
     *
     * @return string The icon class.
     */
    public function getIconClass(): string
    {
        return 'icon-catalogi-widget';

    }//end getIconClass()

    /**
     * Get the widget URL.
     *
     * @return string|null The URL, or null.
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
     * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
     */
    public function load(): void
    {
        Util::addScript(application: Application::APP_ID, file: Application::APP_ID.'-shared-vendor');
        Util::addScript(application: Application::APP_ID, file: Application::APP_ID.'-shared-nc-vue');
        Util::addScript(application: Application::APP_ID, file: Application::APP_ID.'-retentionWidget');
        Util::addStyle(application: Application::APP_ID, file: 'dashboardWidgets');

    }//end load()
}//end class
