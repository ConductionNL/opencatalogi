<?php
/**
 * Stub for OCA\OpenRegister\Event\ToolRegistrationEvent.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Service\ToolRegistry;
use OCP\EventDispatcher\Event;

/**
 * Stub for ToolRegistrationEvent.
 */
class ToolRegistrationEvent extends Event
{

    /**
     * @var ToolRegistry
     */
    private ToolRegistry $registry;

    /**
     * Constructor.
     *
     * @param ToolRegistry $registry The tool registry.
     */
    public function __construct(ToolRegistry $registry)
    {
        parent::__construct();
        $this->registry = $registry;

    }//end __construct()


    /**
     * Register a tool in the registry.
     *
     * @param string              $id       Tool identifier.
     * @param mixed               $tool     Tool instance.
     * @param array<string,mixed> $metadata Tool metadata.
     *
     * @return void
     */
    public function registerTool(string $id, mixed $tool, array $metadata): void
    {
        $this->registry->registerTool($id, $tool, $metadata);

    }//end registerTool()


}//end class
