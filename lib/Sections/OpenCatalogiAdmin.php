<?php
/**
 * OpenCatalogi Admin Settings Section.
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

class OpenCatalogiAdmin implements IIconSection
{

    /** @var IL10N Localization service. */
    private IL10N $l;

    /** @var IURLGenerator URL generator service. */
    private IURLGenerator $urlGenerator;


    /**
     * Constructor for OpenCatalogiAdmin section.
     *
     * @param IL10N         $l            Localization service.
     * @param IURLGenerator $urlGenerator URL generator service.
     */
    public function __construct(IL10N $l, IURLGenerator $urlGenerator)
    {
        $this->l            = $l;
        $this->urlGenerator = $urlGenerator;

    }//end __construct()


    /**
     * Get the section icon URL.
     *
     * @return string The icon URL.
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
     * Get the section display name.
     *
     * @return string The translated section name.
     */
    public function getName(): string
    {
        return $this->l->t('Open Catalogi');

    }//end getName()


    /**
     * Get the section display priority.
     *
     * @return integer The display priority.
     */
    public function getPriority(): int
    {
        return 97;

    }//end getPriority()


}//end class
