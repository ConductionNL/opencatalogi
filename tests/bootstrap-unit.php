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

// Doctrine\DBAL\ParameterType stub — OCP\DB\QueryBuilder\IQueryBuilder references
// it at class-load, but Doctrine\DBAL is a Nextcloud server dependency absent from
// this standalone vendor tree. Load the stub before any QueryBuilder mock resolves.
if (class_exists('Doctrine\\DBAL\\ParameterType') === false) {
    require_once __DIR__.'/Stubs/Doctrine/ParameterType.php';
}

// Register a Doctrine\* autoloader that falls back to the local stubs tree.
// Covers Doctrine\DBAL\Types\Types and any other Doctrine classes the OCP.bak
// stubs reference which are not in the standalone vendor tree.
$doctrineStubDir = __DIR__.'/Stubs/Doctrine';
spl_autoload_register(
        static function (string $class) use ($doctrineStubDir): void {
            $prefix = 'Doctrine\\';
            if (str_starts_with($class, $prefix) === false) {
                return;
            }

            // Strip the leading 'Doctrine\' and map to the stubs directory.
            // e.g. Doctrine\DBAL\Types\Types → Stubs/Doctrine/DBAL/Types/Types.php
            $relative = substr($class, strlen($prefix));
            $path     = $doctrineStubDir.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
            if (file_exists($path) === true) {
                include_once $path;
            }
        }
        );

// Register OC\* stubs — internal Nextcloud classes referenced by OCP.bak stubs.
// Some OCP interfaces (e.g. IRootFolder) extend OC\Hooks\Emitter, which is a
// Nextcloud-internal class not shipped in the vendor/nextcloud/ocp package.
// The bare stubs in tests/Stubs/OC/ cover just the surface needed for PHPUnit to
// resolve the interface chain in a bare php:8.3-cli container.
$ocStubDir = __DIR__.'/Stubs/OC';
spl_autoload_register(
        static function (string $class) use ($ocStubDir): void {
            $prefix = 'OC\\';
            if (str_starts_with($class, $prefix) === false) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path     = $ocStubDir.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
            if (file_exists($path) === true) {
                include_once $path;
            }
        }
        );

// Register OCP (Nextcloud public API from vendor/nextcloud/ocp).
// The package ships `OCP/` as a symlink to `/var/www/html/lib/public` (real NC server)
// and `OCP.bak/` as the actual PHP stubs for bare-CI / static-analysis use.
// When the symlink is broken (bare php:8.3-cli container), fall back to OCP.bak/.
$ocpVendorBase = __DIR__.'/../vendor/nextcloud/ocp';
$ocpDir        = (is_dir($ocpVendorBase.'/OCP') === true)
    ? $ocpVendorBase.'/OCP'
    : $ocpVendorBase.'/OCP.bak';
spl_autoload_register(
        static function (string $class) use ($ocpDir): void {
            $prefix = 'OCP\\';
            if (str_starts_with($class, $prefix) === false) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path     = $ocpDir.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
            if (file_exists($path) === true) {
                include_once $path;
            }
        }
        );

// Resolve the OpenRegister app root. It is installed as a sibling Nextcloud app, but
// the install location varies between environments (dev container, CI, custom_apps).
// Probe a list of known roots plus the OPENREGISTER_PATH env override and use the first
// directory that actually contains the app's lib/ tree.
$openRegisterRoot = (static function (): ?string {
    $candidates = [];
    $envPath    = getenv('OPENREGISTER_PATH');
    if (is_string($envPath) === true && $envPath !== '') {
        $candidates[] = rtrim($envPath, '/');
    }

    $candidates = array_merge(
        $candidates,
        [
            // Dev/local Nextcloud container: apps live under custom_apps.
            '/var/www/html/custom_apps/openregister',
            '/var/www/html/apps/openregister',
            // Legacy hardcoded CI path.
            '/srv/nextcloud/apps/openregister',
            // Sibling checkout relative to this app (apps-extra/opencatalogi → apps-extra/openregister).
            __DIR__.'/../../openregister',
            __DIR__.'/../../../openregister',
        ]
    );

    foreach ($candidates as $candidate) {
        if (is_dir($candidate.'/lib') === true) {
            return $candidate;
        }
    }

    return null;
})();

// Register OCA\OpenRegister from the resolved app root (when available).
// In bare CI containers (php:8.3-cli) OpenRegister is not installed alongside the app;
// the OCA\OpenRegister stub directory provides the minimal surface PHPUnit needs to mock.
$openRegisterStubDir = __DIR__.'/Stubs/OpenRegister';

if ($openRegisterRoot !== null) {
    spl_autoload_register(
            static function (string $class) use ($openRegisterRoot, $openRegisterStubDir): void {
                $prefix = 'OCA\\OpenRegister\\';
                if (str_starts_with($class, $prefix) === false) {
                    return;
                }

                $relative = substr($class, strlen($prefix));

                // Prefer the real app; fall back to the CI stub directory.
                $real = $openRegisterRoot.'/lib/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
                if (file_exists($real) === true) {
                    include_once $real;
                    return;
                }

                $stub = $openRegisterStubDir.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
                if (file_exists($stub) === true) {
                    include_once $stub;
                }
            }
            );
} else {
    // No real OpenRegister found — load stubs for bare CI environment.
    spl_autoload_register(
            static function (string $class) use ($openRegisterStubDir): void {
                $prefix = 'OCA\\OpenRegister\\';
                if (str_starts_with($class, $prefix) === false) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                $stub     = $openRegisterStubDir.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
                if (file_exists($stub) === true) {
                    include_once $stub;
                }
            }
            );
}//end if

// Register the Nextcloud server autoloader so OC\ / Doctrine / Symfony classes referenced
// by OpenRegister (and transitively by OpenCatalogi services) are resolvable. The server
// autoloader is optional: when running in a bare CI container without a full NC tree, the
// individual OCP stubs above still cover the public API surface the unit tests need.
foreach (['/var/www/html/lib/composer/autoload.php', '/var/www/html/3rdparty/autoload.php'] as $serverAutoload) {
    if (file_exists($serverAutoload) === true) {
        require_once $serverAutoload;
    }
}

// OC_Util global stub — OCP\Util references this global class (not OC\*-namespaced),
// so it falls outside the OC\ autoloader. Load the stub file directly.
if (class_exists('OC_Util') === false) {
    require_once __DIR__.'/Stubs/OC/Util.php';
}

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

// Pre-register the handful of framework services that the real OCP\AppFramework\Http
// responses and OCP\Util pull from the server container at runtime. Without these the
// stub container throws as soon as a Response builds its headers (Server::get(IRequest))
// or a dashboard widget calls Util::addScript (Server::get(L10N\IFactory)). The stubs are
// intentionally minimal — just enough surface for the unit tests to exercise our own code.
if (OC::$server instanceof OC_Server_Stub) {
    // IRequest — Response::getHeaders() calls ->getId().
    OC::$server->registerService(
        'OCP\\IRequest',
        static fn() => new class {
            public function getId(): string
            {
                return 'test-request-id';
            }

            public function __call(string $name, array $arguments)
            {
                return null;
            }
        }
    );

    // IUserSession — Response::getHeaders() resolves it to add the X-User-Id header.
    OC::$server->registerService(
        'OCP\\IUserSession',
        static fn() => new class {
            public function getUser(): ?object
            {
                return null;
            }

            public function __call(string $name, array $arguments)
            {
                return null;
            }
        }
    );

    // L10N\IFactory — Util::addScript/addStyle resolve the app version via this factory.
    OC::$server->registerService(
        'OCP\\L10N\\IFactory',
        static fn() => new class {
            public function get(string $app, ?string $lang = null, ?string $locale = null): object
            {
                return new class {
                    public function t(string $text, array $parameters = []): string
                    {
                        return $text;
                    }

                    public function n(string $singular, string $plural, int $count, array $parameters = []): string
                    {
                        return $count === 1 ? $singular : $plural;
                    }
                };
            }

            public function __call(string $name, array $arguments)
            {
                return null;
            }
        }
    );
}//end if

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// OpenCatalogiToolProvider implements this interface in production; the stub keeps the
// class loadable in bare CI containers until the real interface ships.
if (interface_exists('OCA\\OpenRegister\\Mcp\\IMcpToolProvider') === false) {
    require_once __DIR__.'/Stubs/Mcp/IMcpToolProvider.php';
}

// AppHost observability stubs — loaded when the openregister runtime is absent.
// OpenCatalogiMetricsProvider implements IMetricsProvider and returns MetricSample
// objects in production; these stubs keep the provider loadable in bare CI.
if (class_exists('OCA\\OpenRegister\\AppHost\\Observability\\MetricSample') === false) {
    require_once __DIR__.'/Stubs/AppHost/MetricSample.php';
}

if (interface_exists('OCA\\OpenRegister\\AppHost\\IMetricsProvider') === false) {
    require_once __DIR__.'/Stubs/AppHost/IMetricsProvider.php';
}
