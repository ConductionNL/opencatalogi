<?php
/**
 * Stub for OC\Hooks\Emitter.
 *
 * The real implementation lives in the Nextcloud server (not available in bare CI).
 * This stub satisfies the interface extends chain in OCP\Files\IRootFolder which
 * extends both OCP\Files\Folder and OC\Hooks\Emitter.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Stub interface for OC\Hooks\Emitter.
 */
interface Emitter
{

    /**
     * Listen for an event.
     *
     * @param string   $scope    Scope of the event.
     * @param string   $method   Method name.
     * @param callable $callback Callback.
     *
     * @return void
     */
    public function listen(string $scope, string $method, callable $callback): void;

    /**
     * Remove a listener.
     *
     * @param string|null   $scope    Scope, or null for all.
     * @param string|null   $method   Method, or null for all.
     * @param callable|null $callback Callback, or null for all.
     *
     * @return void
     */
    public function removeListener(?string $scope=null, ?string $method=null, ?callable $callback=null): void;

}//end interface
