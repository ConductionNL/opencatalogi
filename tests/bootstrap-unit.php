<?php
/**
 * Lightweight unit-test bootstrap — does NOT require a live Nextcloud installation.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

// Skip the full Nextcloud bootstrap.
define('OC_CONSOLE', 1);

require_once __DIR__.'/../vendor/autoload.php';

// Register OCP (Nextcloud public API from vendor/nextcloud/ocp).
spl_autoload_register(
        static function (string $class): void {
            $prefix = 'OCP\\';
            if (str_starts_with($class, $prefix) === false) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path     = __DIR__.'/../vendor/nextcloud/ocp/OCP/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
            if (file_exists($path) === true) {
                include_once $path;
            }
        }
        );

// Register OCA\OpenRegister (sibling Nextcloud app installed at /srv/nextcloud/apps/openregister).
spl_autoload_register(
        static function (string $class): void {
            $prefix = 'OCA\\OpenRegister\\';
            if (str_starts_with($class, $prefix) === false) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path     = '/srv/nextcloud/apps/openregister/lib/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
            if (file_exists($path) === true) {
                include_once $path;
            }
        }
        );

// Minimal OC stub used by CatalogCacheEventListener tests that call \OC::$server->get().
if (class_exists('OC') === false) {
    class OC
    {

        /**
         * The DI container stub.
         *
         * @var \OC_Server_Stub
         */
        public static $server;
    }//end class

    class OC_Server_Stub
    {

        /**
         * Registered service factories keyed by class name.
         *
         * @var array<string, callable>
         */
        private array $services = [];

        /**
         * Register a service factory.
         *
         * @param string   $name    The service class name.
         * @param callable $factory Factory callable returning the service.
         *
         * @return void
         */
        public function registerService(string $name, callable $factory): void
        {
            $this->services[$name] = $factory;
        }//end registerService()

        /**
         * Retrieve a registered service.
         *
         * @param string $name The service class name.
         *
         * @return mixed The service instance.
         */
        public function get(string $name): mixed
        {
            if (isset($this->services[$name]) === false) {
                throw new \RuntimeException('Service '.$name.' not registered in OC_Server_Stub.');
            }

            return ($this->services[$name])();
        }//end get()
    }//end class

    OC::$server = new OC_Server_Stub();
}//end if
