<?php
/**
 * Pins the load-order invariant that keeps OpenCatalogi's register() safe
 * WITHOUT an autoloader prelude.
 *
 * THE HAZARD
 *
 * OC_App::getEnabledApps() sort()s the app list, and Coordinator::registerApps()
 * calls registerAutoloading() then register() one app at a time. `opencatalogi`
 * sorts before `openregister`, so Application::register() runs BEFORE the
 * OCA\OpenRegister\ prefix is on the autoloader. Anything there that actually
 * RESOLVES an OpenRegister class therefore answers wrongly on a perfectly
 * healthy instance. nldesign measured exactly that: a class_exists() probe at
 * register() time returned FALSE, its listener never registered, and federated
 * config sharing silently did nothing — with no error anywhere.
 *
 * WHY A TEST INSTEAD OF A PRELUDE
 *
 * opencatalogi#812 proposed calling \OC_App::registerAutoloading('openregister')
 * at the top of register(). Measured against this app, that prelude bought
 * nothing and cost three things:
 *
 *   - There is no live defect to fix here. This app calls class_exists() zero
 *     times in lib/ and src/; every OpenRegister reference in register() is
 *     either `Foo::class` on an imported name — a COMPILE-TIME STRING that
 *     never triggers the autoloader, because `use` is an alias and not a load —
 *     or a `new Generic*Controller(...)` inside a `static function ($c)` service
 *     closure that only runs at request time.
 *   - It cannot land without waiving a CORRECT finding: \OC_App is a private
 *     legacy class with no OCP equivalent (IAppManager offers only loadApp(),
 *     which boots the app too early and the prelude rightly rejected), so
 *     PHPMD's StaticAccess is genuine.
 *   - It added 4 untested statements and dropped the coverage ratchet by 0.02%.
 *
 * docudesk reached the same conclusion independently and closed its identical
 * PR (#390) in favour of pinning the invariant (#420). This is opencatalogi's
 * equivalent.
 *
 * So the property worth protecting is not "the autoloader was primed" — it is
 * "register() never needs the autoloader". That is what these tests assert, and
 * they are the thing that would go red if someone later added the probe that
 * broke nldesign.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Asserts Application::register() resolves no OpenRegister class eagerly.
 */
class ApplicationRegisterInvariantTest extends TestCase
{

    /**
     * Absolute path to the file under inspection.
     *
     * @var string
     */
    private const APPLICATION_PHP = __DIR__.'/../../../lib/AppInfo/Application.php';

    /**
     * Read Application.php.
     *
     * The file is inspected as SOURCE rather than reflected over, deliberately:
     * the invariant is about what the method would do at a moment when the
     * OpenRegister autoloader is not yet registered, and a unit run cannot
     * reproduce that moment. Reflection would also force the very resolution
     * the invariant forbids.
     *
     * @return string
     */
    private function source(): string
    {
        $path = realpath(self::APPLICATION_PHP);
        $this->assertNotFalse($path, 'lib/AppInfo/Application.php must exist');

        $src = file_get_contents($path);
        $this->assertIsString($src);
        // Positive control on the reader itself: an empty or truncated read
        // would make every assertion below pass vacuously.
        $this->assertStringContainsString(
            'public function register(',
            $src,
            'read Application.php but found no register() — the reader is broken, so any pass below would be meaningless'
        );

        return $src;

    }//end source()

    /**
     * Extract the body of a method by brace matching.
     *
     * @param string $src        Full file source.
     * @param string $signature  The method signature to find.
     *
     * @return string The method body.
     */
    private function methodBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        $this->assertNotFalse($start, $signature.' not found in Application.php');

        $open = strpos($src, '{', $start);
        $this->assertNotFalse($open, 'no opening brace after '.$signature);

        $depth = 0;
        $len   = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $open, ($i - $open + 1));
                }
            }
        }

        $this->fail('unbalanced braces while extracting '.$signature);

    }//end methodBody()

    /**
     * register() must not probe for a class.
     *
     * This is the nldesign defect in one line. A class_exists() /
     * interface_exists() / enum_exists() call against an OCA\OpenRegister\*
     * name answers FALSE at register() time on a healthy instance, and the
     * `false` branch is silent by construction.
     *
     * @return void
     */
    public function testRegisterDoesNotProbeForClasses(): void
    {
        $body = $this->methodBody($this->source(), 'public function register(');

        foreach (['class_exists', 'interface_exists', 'enum_exists'] as $probe) {
            $this->assertStringNotContainsString(
                $probe.'(',
                $body,
                "Application::register() calls {$probe}(). At register() time the OCA\\OpenRegister\\ "
                ."prefix is not on the autoloader yet — `opencatalogi` sorts before `openregister` — so "
                ."this answers FALSE on a healthy instance and whatever it guards is silently skipped. "
                ."Move the check to boot(), which runs after every app's register() has completed."
            );
        }

    }//end testRegisterDoesNotProbeForClasses()

    /**
     * register() must not eagerly construct an OpenRegister class.
     *
     * `new OCA\OpenRegister\...` at statement level would resolve the class
     * immediately. The four AppHost generics this app binds are constructed
     * INSIDE service closures, which the container only invokes at request
     * time — long after every app has registered. This test pins that
     * containment: it requires each construction to be preceded, within the
     * same method, by a closure opener.
     *
     * @return void
     */
    public function testOpenRegisterClassesAreOnlyConstructedInsideClosures(): void
    {
        $body = $this->methodBody($this->source(), 'public function register(');

        $matches = [];
        preg_match_all('/\bnew\s+(Generic[A-Za-z]*Controller)\s*\(/', $body, $matches, PREG_OFFSET_CAPTURE);

        // The app does bind AppHost generics; if this ever finds none, the test
        // has stopped watching anything and must be revisited rather than
        // quietly passing.
        $this->assertNotEmpty(
            $matches[1],
            'no `new Generic*Controller(` found in register() — this test no longer guards anything'
        );

        foreach ($matches[1] as $match) {
            [$name, $offset] = $match;
            $preceding       = substr($body, 0, $offset);
            $closureAt       = strrpos($preceding, 'static function');

            $this->assertNotFalse(
                $closureAt,
                "`new {$name}(` in register() is not inside a service closure. Constructing an "
                ."OCA\\OpenRegister class at register() time resolves it before the OpenRegister "
                ."autoloader is registered. Keep it inside the `static function (\$c)` passed to "
                ."registerService(), which the container only calls at request time."
            );
        }

    }//end testOpenRegisterClassesAreOnlyConstructedInsideClosures()

    /**
     * The AppHost service KEYS must stay plain strings.
     *
     * `registerService('OCA\\OpenRegister\\...', ...)` with a quoted key is
     * inert text. Rewriting it to `SomeOpenRegisterClass::class` would look
     * tidier and would still be a compile-time string — but importing the class
     * to do so invites the next author to dereference it. Asserting the keys
     * stay quoted keeps the boundary obvious.
     *
     * @return void
     */
    public function testAppHostServiceKeysAreStringLiterals(): void
    {
        $body = $this->methodBody($this->source(), 'public function register(');

        $this->assertMatchesRegularExpression(
            '/registerServiceAlias\(\s*\'OCA\\\\\\\\OpenRegister\\\\\\\\Mcp\\\\\\\\IMcpToolProvider::opencatalogi\'/',
            $body,
            'the MCP tool-provider alias key must stay a quoted string: OpenRegister\'s McpToolsService '
            .'enumerates it by name, and it must not become a resolved class reference at register() time'
        );

    }//end testAppHostServiceKeysAreStringLiterals()
}//end class
