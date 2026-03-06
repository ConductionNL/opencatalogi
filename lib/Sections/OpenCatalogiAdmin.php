<?php
/**
 * OpenCatalogiAdmin for OpenCatalogi.
 *
 * @category Sections
 * @package  OCA\OpenCatalogi\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
     * The localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * The URL generator service.
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;

    /**
     * Constructor for OpenCatalogiAdmin section.
     *
     * @param IL10N         $l            The localization service.
     * @param IURLGenerator $urlGenerator The URL generator service.
     */
    public function __construct(IL10N $l, IURLGenerator $urlGenerator)
    {
        $this->l            = $l;
        $this->urlGenerator = $urlGenerator;

    }//end __construct()

    /**
     * Get the icon for the admin section.
     *
     * @return string The icon path.
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('core', 'actions/settings-dark.svg');

    }//end getIcon()

    /**
     * Get the section identifier.
     *
     * @return string The section ID.
     */
    public function getID(): string
    {
        return 'opencatalogi';

    }//end getID()

    /**
     * Get the display name for the section.
     *
     * @return string The section name.
     */
    public function getName(): string
    {
        return $this->l->t('Open Catalogi');

    }//end getName()

    /**
     * Get the priority for ordering sections.
     *
     * @return integer The section priority.
     */
    public function getPriority(): int
    {
        return 97;

    }//end getPriority()
}//end class
