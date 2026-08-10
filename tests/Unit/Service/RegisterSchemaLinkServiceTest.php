<?php
/**
 * Unit tests for RegisterSchemaLinkService.
 *
 * The service is 309 lines behind a single public method and shipped with no
 * test at all — which is what dropped the coverage ratchet by 0.36% on this
 * change. AttachOrphanSchemasTest, added alongside it, covers a SettingsService
 * transform, not this class.
 *
 * The behaviour worth pinning is not "it links schemas" but WHEN IT DOES
 * NOTHING. reconcile() wraps its whole body in `catch (\Throwable) { return; }`
 * so a broken linkage can never sink an otherwise successful settings import.
 * That is the right call and it is also a silent-failure path: every one of
 * "no register found", "nothing missing", and "the mapper threw" looks
 * identical from the outside — no exception, no return value, no log. The only
 * way to tell them apart is whether update() was called, so that is what these
 * tests assert on.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for RegisterSchemaLinkService::reconcile().
 */
class RegisterSchemaLinkServiceTest extends TestCase
{

    private ContainerInterface|MockObject $container;

    private IAppManager|MockObject $appManager;

    private RegisterSchemaLinkService $service;

    protected function setUp(): void
    {
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        // The mapper accessors gate on OpenRegister being installed; default to
        // installed so the interesting branches are reachable.
        $this->appManager->method('getInstalledApps')->willReturn(['opencatalogi', 'openregister']);

        $this->service = new RegisterSchemaLinkService($this->container, $this->appManager);

    }//end setUp()

    /**
     * Build a double for a class whose shape differs between environments.
     *
     * THIS IS THE WHOLE PROBLEM, AND IT COST A RED CI RUN.
     *
     * Locally the unit suite resolves OCA\OpenRegister\* to the thin doubles in
     * tests/Stubs/, which implement little more than jsonSerialize(). In CI the
     * app is installed next to a real OpenRegister, so the SAME class names
     * resolve to the real entities and mappers. PHPUnit is strict in opposite
     * directions about that difference:
     *
     *   addMethods()  throws if the method EXISTS   (CI, real class)
     *   onlyMethods() throws if it DOES NOT exist   (local, stub)
     *
     * A double hard-coded to either one passes in one environment and errors in
     * the other. This first version used addMethods() throughout: 5/5 green
     * locally, four CannotUseAddMethodsException in CI on getSchemas() and
     * update().
     *
     * Partitioning by method_exists() at runtime is what makes the test mean the
     * same thing in both. It also handles the real entity's magic accessors
     * correctly without a special case: getSlug() comes from Nextcloud Entity's
     * __call, so method_exists() is false for it even on the real class, and it
     * lands in addMethods() exactly as it must.
     *
     * @param string        $class   The class to double.
     * @param array<string> $methods The methods to configure.
     *
     * @return MockObject
     */
    private function environmentAwareDouble(string $class, array $methods): MockObject
    {
        $existing = [];
        $absent   = [];
        foreach ($methods as $method) {
            if (method_exists($class, $method) === true) {
                $existing[] = $method;
            } else {
                $absent[] = $method;
            }
        }

        $builder = $this->getMockBuilder($class)->disableOriginalConstructor();
        if ($existing !== []) {
            $builder->onlyMethods($existing);
        }

        if ($absent !== []) {
            $builder->addMethods($absent);
        }

        return $builder->getMock();

    }//end environmentAwareDouble()

    /**
     * Build a Register test double carrying the given schema list.
     *
     * The durable fix for the stub/real divergence is to widen
     * tests/Stubs/OpenRegister/* to mirror the accessors this app actually
     * depends on — an incomplete stub silently caps what the suite can express,
     * which is one reason this service reached 309 lines untested. That belongs
     * in its own change rather than inside a feature PR, so it is flagged here
     * rather than done here.
     *
     * @param array $schemas The stored `schemas` value.
     *
     * @return Register|MockObject
     */
    private function makeRegister(array $schemas): Register|MockObject
    {
        $register = $this->environmentAwareDouble(
            Register::class,
            ['getSlug', 'getSchemas', 'getConfiguration', 'setSchemas', 'setConfiguration']
        );

        $register->method('getSlug')->willReturn('publication');
        $register->method('getSchemas')->willReturn($schemas);
        $register->method('getConfiguration')->willReturn([]);

        return $register;

    }//end makeRegister()

    /**
     * Build a RegisterMapper double.
     *
     * Same stub-is-thinner-than-the-real-class situation as makeRegister():
     * tests/Stubs/OpenRegister/Db/RegisterMapper.php does not declare find() or
     * update(), so they have to be added to the mock.
     *
     * @return RegisterMapper|MockObject
     */
    private function makeMapper(): RegisterMapper|MockObject
    {
        // find() exists on the stub and update() does not; in CI both exist on
        // the real mapper. environmentAwareDouble() sorts that out per run.
        return $this->environmentAwareDouble(RegisterMapper::class, ['find', 'update']);

    }//end makeMapper()

    /**
     * A schema id already held by the register is not re-linked.
     *
     * This is the idempotence guarantee the docblock claims — the method runs on
     * every import, so a no-op second run is the difference between "safe to
     * call always" and "rewrites the register every time".
     *
     * @return void
     */
    public function testHeldSchemaIsNotRelinked(): void
    {
        $register = $this->makeRegister([7]);

        // Schema's shape differs the same way — see environmentAwareDouble().
        $schema = $this->environmentAwareDouble(\OCA\OpenRegister\Db\Schema::class, ['getId', 'getSlug']);
        $schema->method('getId')->willReturn(7);
        $schema->method('getSlug')->willReturn('held-schema');

        $mapper = $this->makeMapper();
        // The assertion: nothing is persisted. A run that rewrote an unchanged
        // register would still "succeed" and would be invisible without this.
        $mapper->expects($this->never())->method('update');
        $this->container->method('get')->willReturn($mapper);

        $this->service->reconcile(
            [
                'registers' => [$register],
                'schemas'   => [$schema],
            ]
        );

    }//end testHeldSchemaIsNotRelinked()

    /**
     * With no publication register anywhere, the service must not persist.
     *
     * @return void
     */
    public function testNoPublicationRegisterMeansNoWrite(): void
    {
        $mapper = $this->makeMapper();
        $mapper->method('find')->willReturn(null);
        $mapper->expects($this->never())->method('update');
        $this->container->method('get')->willReturn($mapper);

        $this->service->reconcile(['registers' => [], 'schemas' => []]);

    }//end testNoPublicationRegisterMeansNoWrite()

    /**
     * An import result with no schemas at all is a no-op.
     *
     * @return void
     */
    public function testEmptyImportResultIsANoOp(): void
    {
        $register = $this->makeRegister([1, 2]);

        $mapper = $this->makeMapper();
        $mapper->expects($this->never())->method('update');
        $this->container->method('get')->willReturn($mapper);

        $this->service->reconcile(['registers' => [$register], 'schemas' => []]);

    }//end testEmptyImportResultIsANoOp()

    /**
     * A throwing mapper must be swallowed, not propagated.
     *
     * reconcile() runs at the tail of a settings import. If it threw, a
     * successful import would surface to the admin as a failure. The catch is
     * deliberate — this test is what stops someone "tidying" it away, and it is
     * the only executable statement of that intent.
     *
     * @return void
     */
    public function testAThrowingDependencyNeverEscapes(): void
    {
        $register = $this->makeRegister([]);

        $mapper = $this->makeMapper();
        $mapper->method('update')->willThrowException(new \RuntimeException('register table is gone'));
        $this->container->method('get')->willReturn($mapper);

        // Schema's shape differs the same way — see environmentAwareDouble().
        $schema = $this->environmentAwareDouble(\OCA\OpenRegister\Db\Schema::class, ['getId', 'getSlug']);
        $schema->method('getId')->willReturn(42);
        $schema->method('getSlug')->willReturn('new-schema');

        // No expectException: the point is that nothing escapes.
        $this->service->reconcile(
            [
                'registers' => [$register],
                'schemas'   => [$schema],
            ]
        );

        $this->addToAssertionCount(1);

    }//end testAThrowingDependencyNeverEscapes()

    /**
     * An unresolvable container must not escape either.
     *
     * getRegisterMapper() throws RuntimeException when OpenRegister is absent;
     * reconcile() must absorb that exactly as it absorbs a mapper failure.
     *
     * @return void
     */
    public function testMissingOpenRegisterNeverEscapes(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['opencatalogi']);

        $service = new RegisterSchemaLinkService($this->container, $appManager);

        $service->reconcile(['registers' => [], 'schemas' => []]);

        $this->addToAssertionCount(1);

    }//end testMissingOpenRegisterNeverEscapes()
}//end class
