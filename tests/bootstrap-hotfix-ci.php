<?php
/**
 * WOO-572 — PHPUnit bootstrap for the hotfix branch OUTSIDE a Nextcloud install (CI + host).
 *
 * opencatalogi's own tests/bootstrap.php boots the installed Nextcloud
 * (lib/base.php) which only works inside the docker container. For the
 * pure-unit tests ported from OC 1e93e0f3 (PublicationQueryServiceTest,
 * WOO572RepairReadRulesTest) we only need:
 *   1. the worktree's composer autoloader (OCA\OpenCatalogi + PSR + the
 *      nextcloud/ocp dev-package that ships the OCP\* interfaces), and
 *   2. OCA\OpenRegister\* resolved from the sibling openregister worktree
 *      (v1.1.5) so createMock(ObjectService::class) has a real class.
 *
 * Run from the opencatalogi worktree root:
 *   vendor/bin/phpunit --no-coverage --do-not-cache-result \
 *     --bootstrap <plans>/issues/WOO-572/WOO-572-scripts/phpunit-bootstrap-oc-host.php \
 *     tests/Unit/Service/PublicationQueryServiceTest.php
 *
 * Override the OR lib dir with WOO572_OR_LIB. Not part of the app repo.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

$worktree = getcwd();
if ($worktree === false || is_file($worktree . '/vendor/autoload.php') === false) {
    fwrite(STDERR, "[woo572-bootstrap] run phpunit from the worktree root (vendor/autoload.php not found)\n");
    exit(2);
}
require_once $worktree . '/vendor/autoload.php';

$orLib = getenv('WOO572_OR_LIB') ?: (realpath($worktree . '/.ci/openregister/lib') ?: realpath($worktree . '/../openregister-woo572/lib'));
if ($orLib === false || is_dir($orLib) === false) {
    fwrite(STDERR, "[woo572-bootstrap] openregister lib dir not found; set WOO572_OR_LIB\n");
    exit(2);
}
// nextcloud/ocp ships no composer autoload section: map OCP\ and NCU\ by hand.
$ocpRoot = $worktree . '/vendor/nextcloud/ocp';
spl_autoload_register(static function (string $class) use ($ocpRoot): void {
    foreach (['OCP\\', 'NCU\\'] as $prefix) {
        if (str_starts_with($class, $prefix) === true) {
            $file = $ocpRoot . '/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($file) === true) {
                require_once $file;
            }
            return;
        }
    }
});
spl_autoload_register(static function (string $class) use ($orLib): void {
    $prefix = 'OCA\\OpenRegister\\';
    if (str_starts_with($class, $prefix) === false) {
        return;
    }
    $file = $orLib . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});
