<?php

/**
 * Bootstrap file for PHPUnit tests
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenCatalogi.app
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * `lib/base.php` from a source tree that was never installed still declares
 * `OC` and builds `\OC::$server` before it throws "Not installed". That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * `\OC::$server->get()` in the code under test hits a container that knows
 * none of this app's registrations and autowires from scratch; constructor
 * cycles then recurse until memory runs out (one openregister test took 19 GB
 * of RAM and 6 GB of swap on 2026-09-08). So the decision has to be made
 * BEFORE base.php is loaded, and the only cheap signal is the `installed`
 * flag in config/config.php.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function opencatalogi_nc_root_is_installed(string $ncRoot): bool {
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it in a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}

// Bootstrap Nextcloud if not already done.
if (!defined('OC_CONSOLE')) {
	// Try to include the main Nextcloud bootstrap. It is only present when this app
	// is checked out inside a Nextcloud server tree (apps/ or apps-extra/). A bare
	// standalone checkout (CI, a worktree outside the server) has no base.php, and
	// the \OC_App / OC_Hook calls below are meaningless — and fatal — without it.
	$opencatalogiNcRoot = dirname(__DIR__, 3);
	if (file_exists($opencatalogiNcRoot . '/lib/base.php')) {
		if (opencatalogi_nc_root_is_installed($opencatalogiNcRoot) === false) {
			// A bare source tree (config.php empty, absent, or installed => false)
			// is not a root we can boot; say so once and stay in pure-unit mode.
			fwrite(
				STDERR,
				sprintf(
					"[opencatalogi/tests/bootstrap] Nextcloud tree at %s is not installed (config/config.php lacks installed => true); running in pure-unit mode with composer autoload only.\n",
					$opencatalogiNcRoot
				)
			);
		} else {
			try {
				require_once $opencatalogiNcRoot . '/lib/base.php';

				// Load Test\TestCase and other NC test classes (NC convention).
				if (file_exists($opencatalogiNcRoot . '/tests/autoload.php')) {
					require_once $opencatalogiNcRoot . '/tests/autoload.php';
				}

				// Load all enabled apps.
				\OC_App::loadApps();

				// Load our specific app.
				\OC_App::loadApp('opencatalogi');

				// Clear hooks for testing.
				OC_Hook::clear();
			} catch (\Throwable $e) {
				// The root passed the installed check but base.php still failed
				// (unreachable database, broken app, ...). There is no way back to
				// pure-unit mode from here: `OC::$server` is a typed static that
				// already holds a half-built container, and continuing would let
				// every container lookup autowire from scratch until memory runs
				// out. Stop the run and say what to do instead.
				fwrite(
					STDERR,
					sprintf(
						"[opencatalogi/tests/bootstrap] Nextcloud root at %s could not be initialised (%s).\n"
						. "  A half-booted server cannot be undone, so the run stops here rather than pretending to be pure-unit.\n"
						. "  Fix the instance, or run the suite from a checkout outside a Nextcloud tree for pure-unit mode.\n",
						$opencatalogiNcRoot,
						$e->getMessage()
					)
				);
				exit(1);
			}
		}
	}

	unset($opencatalogiNcRoot);
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// OpenCatalogiToolProvider implements this interface in production; the stub keeps the
// class loadable in bare CI containers until the real interface ships.
if (interface_exists('OCA\\OpenRegister\\Mcp\\IMcpToolProvider') === false) {
	require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

// AppHost observability stubs — loaded when the openregister runtime is absent.
// OpenCatalogiMetricsProvider implements IMetricsProvider and returns MetricSample
// objects in production; these stubs keep the provider loadable in bare CI.
if (class_exists('OCA\\OpenRegister\\AppHost\\Observability\\MetricSample') === false) {
	require_once __DIR__ . '/Stubs/AppHost/MetricSample.php';
}

if (interface_exists('OCA\\OpenRegister\\AppHost\\IMetricsProvider') === false) {
	require_once __DIR__ . '/Stubs/AppHost/IMetricsProvider.php';
}
