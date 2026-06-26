<?php
/**
 * Stub for OC\AppScriptDependency.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OC;

/**
 * Stub for OC\AppScriptDependency.
 */
class AppScriptDependency
{

    /**
     * @var string
     */
    private string $id;

    /**
     * @var array<string>
     */
    private array $deps;

    /**
     * Constructor.
     *
     * @param string        $id   Script ID.
     * @param array<string> $deps Dependencies.
     */
    public function __construct(string $id, array $deps=[])
    {
        $this->id   = $id;
        $this->deps = $deps;

    }//end __construct()


    /**
     * Get the ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;

    }//end getId()


    /**
     * Get dependencies.
     *
     * @return array<string>
     */
    public function getDeps(): array
    {
        return $this->deps;

    }//end getDeps()


    /**
     * Add a dependency.
     *
     * @param string $dep Dependency ID.
     *
     * @return void
     */
    public function addDep(string $dep): void
    {
        $this->deps[] = $dep;

    }//end addDep()


}//end class
