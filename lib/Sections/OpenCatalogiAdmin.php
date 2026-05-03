<?php
/**
 * OpenCatalogi admin section for settings.
 *
 * @category Sections
 * @package  OCA\OpenCatalogi\Sections
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
 */

namespace OCA\OpenCatalogi\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin section for OpenCatalogi settings.
 */
class OpenCatalogiAdmin implements IIconSection
{

    /**
     * Localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * URL generator.
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;

    /**
     * Constructor.
     *
     * @param IL10N         $l            Localization service.
     * @param IURLGenerator $urlGenerator URL generator.
     */
    public function __construct(IL10N $l, IURLGenerator $urlGenerator)
    {
        $this->l            = $l;
        $this->urlGenerator = $urlGenerator;

    }//end __construct()

    /**
     * Get the section icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(appName: 'opencatalogi', file: 'app-dark.svg');

    }//end getIcon()

    /**
     * Get the section ID.
     *
     * @return string
     */
    public function getID(): string
    {
        return 'opencatalogi';

    }//end getID()

    /**
     * Get the section name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l->t('Open Catalogi');

    }//end getName()

    /**
     * Get the section priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 97;

    }//end getPriority()
}//end class
