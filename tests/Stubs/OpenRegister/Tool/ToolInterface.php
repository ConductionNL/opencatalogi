<?php
/**
 * Stub for OCA\OpenRegister\Tool\ToolInterface.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tool;

use OCA\OpenRegister\Db\Agent;

/**
 * Stub interface for ToolInterface.
 */
interface ToolInterface
{

    /**
     * Get the tool name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the tool description.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get available functions.
     *
     * @return array<mixed>
     */
    public function getFunctions(): array;

    /**
     * Execute a function.
     *
     * @param string      $functionName Function to execute.
     * @param array<mixed> $parameters  Parameters.
     * @param string|null $userId       User ID.
     *
     * @return array<mixed>
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId=null): array;

    /**
     * Set the agent context.
     *
     * @param Agent|null $agent The agent.
     *
     * @return void
     */
    public function setAgent(?Agent $agent): void;

}//end interface
