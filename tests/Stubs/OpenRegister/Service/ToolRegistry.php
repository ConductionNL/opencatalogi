<?php
/**
 * Stub for OCA\OpenRegister\Service\ToolRegistry.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for ToolRegistry used by PHPUnit mocks in bare CI.
 */
class ToolRegistry
{

    /**
     * Register a tool.
     *
     * @param string $id       Tool identifier.
     * @param mixed  $tool     Tool instance.
     * @param array<string,mixed> $metadata Tool metadata.
     *
     * @return void
     */
    public function registerTool(string $id, mixed $tool, array $metadata): void
    {
        // stub — no-op

    }//end registerTool()


    /**
     * Get a registered tool.
     *
     * @param string $id Tool identifier.
     *
     * @return mixed
     */
    public function getTool(string $id): mixed
    {
        return null;

    }//end getTool()


    /**
     * Get all registered tools.
     *
     * @return array<mixed>
     */
    public function getAllTools(): array
    {
        return [];

    }//end getAllTools()


}//end class
